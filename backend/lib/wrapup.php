<?php

require_once __DIR__ . '/grades.php';
require_once __DIR__ . '/planner.php';

/**
 * Checks the "finish my semester" form. Each current course gets one result: a final grade (A-F),
 * "keep" (still current, e.g. no grade yet) or "drop" (remove without a grade).
 *
 * @param array $input            the request body
 * @param int[] $currentCourseIds the courses the student is taking now
 * @return array{0: array, 1: array} cleaned values, and errors keyed by course id or field name
 */
function clean_wrapup_input(array $input, array $currentCourseIds): array
{
    $errors = [];
    $courses = [];
    $seen = [];
    $clear = parse_available_flag($input['clear_schedule'] ?? false) === true;
    $list = $input['courses'] ?? [];
    if (!is_array($list)) {
        return [['courses' => [], 'clear_schedule' => $clear], ['courses' => 'Send a list of courses.']];
    }

    foreach ($list as $item) {
        $id = is_array($item) ? (int) ($item['course_id'] ?? 0) : 0;
        if (!in_array($id, $currentCourseIds, true) || isset($seen[$id])) {
            $errors['courses'] = 'That list does not match your current courses. Reload the page and try again.';
            continue;
        }
        $seen[$id] = true;

        $raw = $item['result'] ?? null;
        $word = is_string($raw) ? strtolower(trim($raw)) : '';
        $result = ($word === 'keep' || $word === 'drop') ? $word : clean_grade($raw);
        if ($result === null) {
            $errors[$id] = 'Choose a final grade, "no grade yet" or "remove".';
            continue;
        }

        $available = false;
        if (strlen($result) === 1 && grade_is_passing($result)) {
            $answer = parse_available_flag($item['available'] ?? null);
            if ($answer === null) {
                $errors[$id] = 'Say whether you are available to buddy with students in this class.';
                continue;
            }
            $available = $answer;
        }
        $courses[] = ['course_id' => $id, 'result' => $result, 'available' => $available];
    }

    $changing = array_filter($courses, fn ($c) => $c['result'] !== 'keep');
    if (!$errors && !$changing && !$clear) {
        $errors['courses'] = 'Nothing to change yet. Enter at least one grade.';
    }
    return [['courses' => $courses, 'clear_schedule' => $clear], $errors];
}

/**
 * Saves a checked wrap-up in one transaction: all of it happens or none of it.
 *
 * @return array{graded: int, dropped: int, helping: int} what changed
 */
function apply_wrapup(PDO $pdo, int $userId, array $clean): array
{
    // Table creation first: MySQL ends a transaction when a table is created or altered.
    ensure_standing_tables($pdo);
    ensure_planner_tables($pdo);

    $summary = ['graded' => 0, 'dropped' => 0, 'helping' => 0];
    $pdo->beginTransaction();
    try {
        foreach ($clean['courses'] as $course) {
            if ($course['result'] === 'keep') {
                continue;
            }
            if ($course['result'] === 'drop') {
                $summary['dropped']++;
            } else {
                save_course_grade($pdo, $userId, $course['course_id'], $course['result'], $course['available']);
                $summary['graded']++;
                $summary['helping'] += $course['available'] ? 1 : 0;
            }
            // A finished course leaves the current list, along with its class times.
            foreach (['user_courses', 'course_progress', 'schedule_blocks'] as $table) {
                $pdo->prepare("DELETE FROM $table WHERE user_id = ? AND course_id = ?")
                    ->execute([$userId, $course['course_id']]);
            }
        }
        if ($clean['clear_schedule']) {
            // Work and other weekly times. Class times for courses that are staying are left alone.
            $pdo->prepare('DELETE FROM schedule_blocks WHERE user_id = ? AND course_id IS NULL')->execute([$userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $summary;
}
