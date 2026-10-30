<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/grades.php';

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$courseId = (int) ($input['course_id'] ?? 0);
$available = parse_available_flag($input['available'] ?? null);

if ($courseId <= 0 || $available === null) {
    fail(422, 'Choose yes or no for one of your completed courses.');
}

$pdo = get_db();
ensure_standing_tables($pdo);

$stmt = $pdo->prepare('SELECT grade FROM course_grades WHERE user_id = ? AND course_id = ?');
$stmt->execute([$userId, $courseId]);
$grade = $stmt->fetchColumn();
if ($grade === false) {
    fail(404, 'That course is not in your completed courses.');
}
if ($available && !grade_is_passing((string) $grade)) {
    fail(409, 'You can offer help only in courses you passed with a C or better.');
}

$pdo->prepare('UPDATE course_grades SET available_to_help = ? WHERE user_id = ? AND course_id = ?')
    ->execute([$available ? 1 : 0, $userId, $courseId]);

echo json_encode(['success' => true, 'available_to_help' => $available]);
