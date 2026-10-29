<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/grades.php';
require_once __DIR__ . '/lib/courses.php';

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$pdo = get_db();
ensure_standing_tables($pdo);

// Remove a completed course from my history.
if (!empty($input['remove_course_id'])) {
    $pdo->prepare('DELETE FROM course_grades WHERE user_id = ? AND course_id = ?')
        ->execute([$userId, (int) $input['remove_course_id']]);
    echo json_encode(['success' => true]);
    exit;
}

$grade = clean_grade($input['grade'] ?? null);
if ($grade === null) {
    fail(422, 'Choose a grade from A to F.');
}

$course = is_array($input['course'] ?? null) ? $input['course'] : [];
$code = trim((string) ($course['course_code'] ?? ''));
$section = trim((string) ($course['section'] ?? ''));
$name = trim((string) ($course['course_name'] ?? ''));
if ($code === '' || mb_strlen($code) > 20 || mb_strlen($section) > 10 || mb_strlen($name) > 255) {
    fail(422, 'Choose a course first.');
}

$courseId = find_or_create_course($pdo, $code, $section, $name);

$sql = db_is_postgres()
    ? 'INSERT INTO course_grades (user_id, course_id, grade) VALUES (?, ?, ?)
       ON CONFLICT (user_id, course_id) DO UPDATE SET grade = EXCLUDED.grade, updated_at = CURRENT_TIMESTAMP'
    : 'INSERT INTO course_grades (user_id, course_id, grade) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE grade = VALUES(grade), updated_at = CURRENT_TIMESTAMP';
$pdo->prepare($sql)->execute([$userId, $courseId, $grade]);

// A finished course is no longer a current one.
$pdo->prepare('DELETE FROM user_courses WHERE user_id = ? AND course_id = ?')->execute([$userId, $courseId]);
$pdo->prepare('DELETE FROM course_progress WHERE user_id = ? AND course_id = ?')->execute([$userId, $courseId]);

echo json_encode(['success' => true, 'course_id' => $courseId, 'grade' => $grade, 'passed' => grade_is_passing($grade)]);
