<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$receiverId = (int) ($input['receiver_id'] ?? 0);

if ($receiverId <= 0 || $receiverId === $userId) {
    fail(422, 'Pick another student to send a request to.');
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
$stmt->execute([$receiverId]);
if (!$stmt->fetchColumn()) {
    fail(404, 'That student no longer exists.');
}

// Only classmates can be sent a request, which also keeps strangers from being spammed.
$stmt = $pdo->prepare('
    SELECT a.course_id
    FROM user_courses a
    JOIN user_courses b ON b.course_id = a.course_id
    WHERE a.user_id = ? AND b.user_id = ?
    ORDER BY a.course_id
');
$stmt->execute([$userId, $receiverId]);
$courseId = $stmt->fetchColumn();
if (!$courseId) {
    fail(403, 'You can only send requests to students who share a course with you.');
}

$stmt = $pdo->prepare('
    SELECT 1 FROM buddy_requests
    WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
');
$stmt->execute([$userId, $receiverId, $receiverId, $userId]);
if ($stmt->fetchColumn()) {
    fail(409, 'There is already a request between you two.');
}

$pdo->prepare('INSERT INTO buddy_requests (sender_id, receiver_id, course_id) VALUES (?, ?, ?)')
    ->execute([$userId, $receiverId, $courseId]);

echo json_encode(['success' => true, 'request_status' => 'pending']);
