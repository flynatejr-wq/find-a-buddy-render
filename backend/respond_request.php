<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/messages.php';

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$requestId = (int) ($input['request_id'] ?? 0);
$status = request_action_to_status($input['action'] ?? null);

if ($requestId <= 0 || $status === null) {
    fail(422, 'Choose accept or decline for a valid request.');
}

$pdo = get_db();
$stmt = $pdo->prepare('SELECT receiver_id, status FROM buddy_requests WHERE id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    fail(404, 'That request does not exist.');
}
if ((int) $request['receiver_id'] !== $userId) {
    fail(403, 'Only the student who received a request can answer it.');
}
if ($request['status'] !== 'pending') {
    fail(409, 'That request was already answered.');
}

$cast = db_is_postgres() ? 'CAST(? AS buddy_request_status)' : '?';
$pdo->prepare("UPDATE buddy_requests SET status = $cast WHERE id = ? AND status = 'pending'")
    ->execute([$status, $requestId]);

echo json_encode(['success' => true, 'status' => $status]);
