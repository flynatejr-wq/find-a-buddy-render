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
$clear = array_key_exists('status', $input) && $input['status'] === null;
$status = $clear ? null : clean_progress($input['status'] ?? null);

if ($courseId <= 0 || (!$clear && $status === null)) {
    fail(422, 'Choose passing or failing for one of your courses.');
}

$pdo = get_db();
ensure_standing_tables($pdo);

// Progress only makes sense for a course you are currently taking.
$stmt = $pdo->prepare('SELECT 1 FROM user_courses WHERE user_id = ? AND course_id = ?');
$stmt->execute([$userId, $courseId]);
if (!$stmt->fetchColumn()) {
    fail(404, 'That course is not in your current courses.');
}

if ($clear) {
    $pdo->prepare('DELETE FROM course_progress WHERE user_id = ? AND course_id = ?')->execute([$userId, $courseId]);
    echo json_encode(['success' => true, 'status' => null]);
    exit;
}

$sql = db_is_postgres()
    ? 'INSERT INTO course_progress (user_id, course_id, status) VALUES (?, ?, ?)
       ON CONFLICT (user_id, course_id) DO UPDATE SET status = EXCLUDED.status, updated_at = CURRENT_TIMESTAMP'
    : 'INSERT INTO course_progress (user_id, course_id, status) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = CURRENT_TIMESTAMP';
$pdo->prepare($sql)->execute([$userId, $courseId, $status]);

echo json_encode(['success' => true, 'status' => $status]);
