<?php

// The database side of the AI study assistant: the student's context, the tools the model can
// call, chat history, usage limits, and the function that handles one chat message.

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/courses.php';
require_once __DIR__ . '/grades.php';
require_once __DIR__ . '/planner.php';
require_once __DIR__ . '/planner_data.php';
require_once __DIR__ . '/match_data.php';

const AI_MAX_COURSES_PER_STUDENT = 15;

/** Everything the model may know about this student. Never includes emails, last names or grades. */
function ai_build_context(PDO $pdo, int $userId): array
{
    $planner = compute_planner_data($pdo, $userId);

    $stmt = $pdo->prepare('SELECT u.first_name, u.availability, p.major, p.year FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
    $stmt->execute([$userId]);
    $me = $stmt->fetch() ?: [];

    $stmt = $pdo->prepare('
        SELECT c.course_code, g.grade, g.available_to_help
        FROM course_grades g JOIN courses c ON c.id = g.course_id
        WHERE g.user_id = ? ORDER BY c.course_code
    ');
    $stmt->execute([$userId]);

    return [
        'first_name' => ai_clean_text($me['first_name'] ?? '', 30),
        'major' => ai_clean_text($me['major'] ?? '', 60),
        'year' => ai_clean_text($me['year'] ?? '', 20),
        'current_courses' => array_map(fn($c) => [
            'code' => ai_clean_text($c['course_code'], 20),
            'name' => ai_clean_text($c['course_name'], 80),
            'progress' => $c['progress'] ?? 'not set',
        ], $planner['courses']),
        'completed_courses' => array_map(fn($g) => [
            'code' => ai_clean_text($g['course_code'], 20),
            'passed' => grade_is_passing($g['grade']),
            'open_to_help_others' => (bool) $g['available_to_help'],
        ], $stmt->fetchAll()),
        'class_schedule' => array_map(fn($b) => [
            'day' => DAY_NAMES[$b['day']],
            'start' => $b['start'],
            'end' => $b['end'],
            'what' => ai_clean_text($b['label'], 60),
        ], $planner['blocks']),
        'study_goal' => [
            'hours_per_week' => $planner['prefs']['hours'],
            'earliest' => $planner['prefs']['day_start'],
            'latest' => $planner['prefs']['day_end'],
        ],
        'free_to_meet_buddies' => decode_availability($me['availability'] ?? ''),
    ];
}

/** Runs one tool for this student. Returns the result for the model; screen data goes into $effects. */
function ai_execute_tool(PDO $pdo, int $userId, string $name, array $input, array &$effects): array
{
    switch ($name) {
        case 'add_courses':
            return ai_tool_add_courses($pdo, $userId, $input, $effects);
        case 'save_class_schedule':
            return ai_tool_save_class_schedule($pdo, $userId, $input, $effects);
        case 'build_study_plan':
            return ai_tool_build_study_plan($pdo, $userId, $input, $effects);
        case 'recommend_study_partners':
            return ai_tool_recommend_partners($pdo, $userId, $input, $effects);
    }
    throw new AiToolError("There is no tool called $name.");
}

function ai_tool_add_courses(PDO $pdo, int $userId, array $input, array &$effects): array
{
    $list = $input['courses'] ?? null;
    if (!is_array($list) || !$list || count($list) > 10) {
        throw new AiToolError('Give a list of 1 to 10 courses.');
    }

    $stmt = $pdo->prepare('SELECT c.id, c.course_code FROM user_courses uc JOIN courses c ON c.id = uc.course_id WHERE uc.user_id = ?');
    $stmt->execute([$userId]);
    $mine = [];
    foreach ($stmt->fetchAll() as $row) {
        $mine[(int) $row['id']] = normalize_course_code($row['course_code']);
    }

    $added = [];
    $already = [];
    $skipped = [];
    foreach ($list as $c) {
        $code = normalize_course_code((string) ($c['course_code'] ?? ''));
        if ($code === '' || mb_strlen($code) > 20) {
            $skipped[] = ['course_code' => ai_clean_text($c['course_code'] ?? '', 30), 'reason' => 'not a valid course code'];
            continue;
        }
        if (in_array($code, $mine, true)) {
            $already[] = $code;
            continue;
        }
        if (count($mine) >= AI_MAX_COURSES_PER_STUDENT) {
            $skipped[] = ['course_code' => $code, 'reason' => 'the student already has the maximum number of courses'];
            continue;
        }

        // Prefer an existing catalog entry for this code (any section) over creating a duplicate.
        $stmt = $pdo->prepare('SELECT id FROM courses WHERE UPPER(course_code) = ? ORDER BY (section IS NULL) DESC, id LIMIT 1');
        $stmt->execute([$code]);
        $courseId = $stmt->fetchColumn();
        if (!$courseId) {
            $courseId = find_or_create_course($pdo, $code, '', mb_substr(trim((string) ($c['course_name'] ?? '')), 0, 255));
        }
        $courseId = (int) $courseId;

        $pdo->prepare(db_is_postgres()
            ? 'INSERT INTO user_courses (user_id, course_id) VALUES (?, ?) ON CONFLICT (user_id, course_id) DO NOTHING'
            : 'INSERT IGNORE INTO user_courses (user_id, course_id) VALUES (?, ?)')->execute([$userId, $courseId]);
        $mine[$courseId] = $code;
        $added[] = $code;
    }

    if ($added) {
        $effects['changes'][] = 'Added to your courses: ' . implode(', ', $added);
    }
    return ['added' => $added, 'already_in_list' => $already, 'skipped' => $skipped];
}

function ai_tool_save_class_schedule(PDO $pdo, int $userId, array $input, array &$effects): array
{
    $classes = $input['classes'] ?? null;
    if (!is_array($classes) || !$classes || count($classes) > 20) {
        throw new AiToolError('Give a list of 1 to 20 classes.');
    }
    $replace = ($input['replace_existing'] ?? false) === true;

    ensure_planner_tables($pdo);
    $stmt = $pdo->prepare('SELECT c.id, c.course_code FROM user_courses uc JOIN courses c ON c.id = uc.course_id WHERE uc.user_id = ?');
    $stmt->execute([$userId]);
    $byCode = [];
    foreach ($stmt->fetchAll() as $row) {
        $byCode[normalize_course_code($row['course_code'])] = (int) $row['id'];
    }

    // Validate everything first, so a replace never wipes the schedule and then saves nothing.
    $valid = [];
    $skipped = [];
    foreach ($classes as $c) {
        $code = normalize_course_code((string) ($c['course_code'] ?? ''));
        $label = trim((string) ($c['label'] ?? ''));
        $courseId = 0;
        if ($code !== '') {
            if (!isset($byCode[$code])) {
                $skipped[] = ['item' => $code, 'reason' => "$code is not in the student's courses. Call add_courses first."];
                continue;
            }
            $courseId = $byCode[$code];
        }
        [$clean, $errors] = clean_block_input([
            'day' => parse_day($c['day'] ?? null),
            'start' => $c['start'] ?? null,
            'end' => $c['end'] ?? null,
            'course_id' => $courseId,
            'label' => $label,
        ]);
        if ($errors) {
            $skipped[] = ['item' => $code !== '' ? $code : ai_clean_text($label, 30), 'reason' => implode(' ', $errors)];
            continue;
        }
        $valid[] = $clean;
    }

    if (!$valid) {
        return ['saved' => 0, 'skipped' => $skipped, 'note' => 'Nothing was saved and the existing schedule was left alone.'];
    }

    if ($replace) {
        $pdo->prepare('DELETE FROM schedule_blocks WHERE user_id = ?')->execute([$userId]);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schedule_blocks WHERE user_id = ?');
    $stmt->execute([$userId]);
    $room = PLAN_MAX_BLOCKS - (int) $stmt->fetchColumn();

    $saved = 0;
    foreach ($valid as $b) {
        if ($saved >= $room) {
            $skipped[] = ['item' => 'extra items', 'reason' => 'the planner holds at most ' . PLAN_MAX_BLOCKS . ' items'];
            break;
        }
        $pdo->prepare('INSERT INTO schedule_blocks (user_id, course_id, label, day, start_min, end_min) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $b['course_id'], $b['label'], $b['day'], $b['start_min'], $b['end_min']]);
        $saved++;
    }

    if ($saved) {
        $effects['changes'][] = ($replace ? 'Replaced your class schedule with ' : 'Added to your class schedule: ') . $saved . ($saved === 1 ? ' item' : ' items');
    }
    return ['saved' => $saved, 'replaced_existing' => $replace, 'skipped' => $skipped];
}

function ai_tool_build_study_plan(PDO $pdo, int $userId, array $input, array &$effects): array
{
    $before = compute_planner_data($pdo, $userId);
    if (!$before['courses']) {
        throw new AiToolError("The student has no current courses yet. Ask which courses they are taking and call add_courses first.");
    }

    $changesGoal = isset($input['hours_per_week']) || isset($input['earliest']) || isset($input['latest']);
    if ($changesGoal) {
        [$clean, $errors] = clean_planner_prefs([
            'hours' => $input['hours_per_week'] ?? $before['prefs']['hours'],
            'day_start' => $input['earliest'] ?? $before['prefs']['day_start'],
            'day_end' => $input['latest'] ?? $before['prefs']['day_end'],
        ]);
        if ($errors) {
            throw new AiToolError(implode(' ', $errors));
        }
        save_planner_prefs($pdo, $userId, $clean);
        $effects['changes'][] = sprintf(
            'Study goal set to %d hours a week, %s to %s',
            $clean['hours'], format_hhmm($clean['day_start']), format_hhmm($clean['day_end'])
        );
    }

    $data = compute_planner_data($pdo, $userId);
    $effects['plan'] = $data;

    // A compact version for the model.
    $days = [];
    foreach ($data['plan']['blocks'] as $b) {
        $days[DAY_NAMES[$b['day']]][] = "{$b['start']}-{$b['end']} study {$b['course_code']}" . ($b['priority'] ? ' (priority)' : '');
    }
    $classes = [];
    foreach ($data['blocks'] as $b) {
        $classes[DAY_NAMES[$b['day']]][] = "{$b['start']}-{$b['end']} " . ai_clean_text($b['label'], 60);
    }
    return [
        'study_goal' => $data['prefs'],
        'study_blocks_by_day' => $days,
        'class_times_by_day' => $classes,
        'per_course' => array_map(fn($s) => [
            'course' => ai_clean_text($s['course_code'], 20),
            'progress' => $s['progress'] ?? 'not set',
            'planned_hours' => round($s['planned_minutes'] / 60, 1),
        ], $data['plan']['summary']),
        'minutes_that_did_not_fit' => $data['plan']['unplaced_minutes'],
        'note' => 'The app is showing this plan to the student on screen.',
    ];
}

function ai_tool_recommend_partners(PDO $pdo, int $userId, array $input, array &$effects): array
{
    $limit = max(1, min(8, (int) ($input['limit'] ?? 5)));
    $filter = isset($input['course_code']) ? normalize_course_code((string) $input['course_code']) : '';

    $result = compute_matches($pdo, $userId);
    if (!$result['has_courses']) {
        return ['partners' => [], 'note' => 'The student has no current courses or completed courses to match on yet.'];
    }

    $matches = array_values(array_filter($result['matches'], function ($m) use ($filter) {
        if ($m['request_status'] === 'accepted') {
            return false; // already buddies
        }
        if ($filter === '') {
            return true;
        }
        $codes = array_merge(array_column($m['shared_courses'], 'course_code'), $m['can_help_you'], $m['you_can_help']);
        return in_array($filter, array_map('normalize_course_code', $codes), true);
    }));
    $matches = array_slice($matches, 0, $limit);

    $effects['cards'] = $matches;

    // First name, year and course codes only. Free text from other students is left out.
    return [
        'partners' => array_map(fn($m) => [
            'first_name' => ai_clean_text($m['first_name'], 30),
            'year' => ai_clean_text($m['year'] ?? '', 20),
            'match_score' => $m['score'],
            'shares_courses' => array_map(fn($c) => ai_clean_text($c['course_code'], 20), $m['shared_courses']),
            'passed_a_course_the_student_is_failing' => array_map(fn($c) => ai_clean_text($c, 20), $m['can_help_you']),
            'student_could_help_with' => array_map(fn($c) => ai_clean_text($c, 20), $m['you_can_help']),
            'overlapping_free_time_slots' => count($m['shared_slots']),
            'request_already_sent' => $m['request_status'] === 'pending',
        ], $matches),
        'note' => $matches ? 'The app is showing these people on screen with a request button.' : 'No matches yet. Suggest adding courses or checking back as more students join.',
    ];
}

/** How many messages this student and the whole site have sent to the assistant today. */
function ai_usage_today(PDO $pdo, int $userId): array
{
    $today = db_is_postgres() ? 'CURRENT_DATE' : 'CURDATE()';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mascot_messages WHERE user_id = ? AND sender = 'user' AND created_at >= $today");
    $stmt->execute([$userId]);
    $mine = (int) $stmt->fetchColumn();
    $all = (int) $pdo->query("SELECT COUNT(*) FROM mascot_messages WHERE sender = 'user' AND created_at >= $today")->fetchColumn();
    return ['user' => $mine, 'global' => $all];
}

function ai_store_message(PDO $pdo, int $userId, string $sender, string $text): void
{
    $cast = db_is_postgres() ? 'CAST(? AS mascot_sender)' : '?';
    $pdo->prepare("INSERT INTO mascot_messages (user_id, sender, message) VALUES (?, $cast, ?)")->execute([$userId, $sender, $text]);
}

/** The student's saved chat, oldest first. */
function ai_recent_messages(PDO $pdo, int $userId, int $limit): array
{
    $stmt = $pdo->prepare("
        SELECT sender, message, created_at FROM (
            SELECT id, sender, message, created_at FROM mascot_messages WHERE user_id = ? ORDER BY id DESC LIMIT $limit
        ) recent ORDER BY id ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Handles one chat message: saves it, asks the model (running tools as needed), saves the answer.
 * Returns what the page shows: the reply, plus any partner cards, plan and data changes.
 */
function ai_handle_chat(PDO $pdo, int $userId, string $message, Anthropic\Client $client): array
{
    ai_store_message($pdo, $userId, 'user', $message);

    $system = ai_system_prompt(ai_build_context($pdo, $userId));
    $messages = ai_history_for_model(ai_recent_messages($pdo, $userId, AI_HISTORY_ROWS));
    $effects = ['cards' => [], 'plan' => null, 'changes' => []];

    $send = fn(array $m) => $client->messages->create(
        model: ai_model(),
        maxTokens: AI_MAX_TOKENS,
        system: $system,
        messages: $m,
        tools: ai_tool_definitions(),
        outputConfig: ['effort' => ai_effort()],
    );

    // A full closure with use (&$effects): an arrow function would capture a copy and lose the results.
    $runTool = function (string $name, array $input) use ($pdo, $userId, &$effects): array {
        return ai_execute_tool($pdo, $userId, $name, $input, $effects);
    };

    $result = ai_run_conversation($send, $messages, $runTool, AI_MAX_ROUNDS, microtime(true) + 75);

    ai_store_message($pdo, $userId, 'mascot', $result['text']);

    // If the schedule changed after the plan was built, show the up-to-date plan.
    if ($effects['plan'] !== null) {
        $effects['plan'] = compute_planner_data($pdo, $userId);
    }

    return [
        'reply' => $result['text'],
        'recommendations' => $effects['cards'],
        'plan' => $effects['plan'],
        'changes' => $effects['changes'],
    ];
}
