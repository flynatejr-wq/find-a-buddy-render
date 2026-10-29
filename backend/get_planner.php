<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/grades.php';
require_once __DIR__ . '/lib/planner.php';

$userId = require_login();
$pdo = get_db();
ensure_standing_tables($pdo);
ensure_planner_tables($pdo);

// Saved study goal, or the defaults.
$stmt = $pdo->prepare('SELECT hours, day_start, day_end FROM planner_settings WHERE user_id = ?');
$stmt->execute([$userId]);
$saved = $stmt->fetch();
$hours = $saved ? (int) $saved['hours'] : PLAN_DEFAULT_HOURS;
$dayStart = $saved ? (int) $saved['day_start'] : PLAN_DEFAULT_START;
$dayEnd = $saved ? (int) $saved['day_end'] : PLAN_DEFAULT_END;

// Courses I am taking now, and how I am doing in each.
$stmt = $pdo->prepare('
    SELECT c.id, c.course_code, c.course_name, p.status AS progress
    FROM user_courses uc
    JOIN courses c ON c.id = uc.course_id
    LEFT JOIN course_progress p ON p.user_id = uc.user_id AND p.course_id = uc.course_id
    WHERE uc.user_id = ?
    ORDER BY c.course_code
');
$stmt->execute([$userId]);
$courses = $stmt->fetchAll();

// My class times and other commitments.
$stmt = $pdo->prepare('
    SELECT b.id, b.course_id, b.label, b.day, b.start_min, b.end_min, c.course_code
    FROM schedule_blocks b
    LEFT JOIN courses c ON c.id = b.course_id
    WHERE b.user_id = ?
    ORDER BY b.day, b.start_min
');
$stmt->execute([$userId]);
$rows = $stmt->fetchAll();

$busyByDay = [];
$blocks = [];
foreach ($rows as $r) {
    $busyByDay[(int) $r['day']][] = [(int) $r['start_min'], (int) $r['end_min']];
    $blocks[] = [
        'id' => (int) $r['id'],
        'day' => (int) $r['day'],
        'start' => format_hhmm((int) $r['start_min']),
        'end' => format_hhmm((int) $r['end_min']),
        'course_id' => $r['course_id'] !== null ? (int) $r['course_id'] : null,
        'label' => $r['course_code'] ?? $r['label'],
    ];
}

$plan = generate_plan($busyByDay, $courses, $hours * 60, $dayStart, $dayEnd);

$byId = [];
foreach ($courses as $c) {
    $byId[(int) $c['id']] = $c;
}
$planBlocks = array_map(fn($b) => [
    'day' => $b['day'],
    'start' => format_hhmm($b['start']),
    'end' => format_hhmm($b['end']),
    'course_id' => $b['course_id'],
    'course_code' => $byId[$b['course_id']]['course_code'],
    'priority' => ($byId[$b['course_id']]['progress'] ?? null) === 'failing',
], $plan['blocks']);

$summary = [];
foreach ($courses as $c) {
    $id = (int) $c['id'];
    $summary[] = [
        'course_id' => $id,
        'course_code' => $c['course_code'],
        'progress' => $c['progress'],
        'wanted_minutes' => $plan['wanted'][$id] ?? 0,
        'planned_minutes' => $plan['planned'][$id] ?? 0,
    ];
}

echo json_encode([
    'prefs' => ['hours' => $hours, 'day_start' => format_hhmm($dayStart), 'day_end' => format_hhmm($dayEnd)],
    'courses' => $courses,
    'blocks' => $blocks,
    'plan' => ['blocks' => $planBlocks, 'summary' => $summary, 'unplaced_minutes' => $plan['unplaced_minutes']],
]);
