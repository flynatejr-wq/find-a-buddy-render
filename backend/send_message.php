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
$to = (int) ($input['to'] ?? 0);
$body = clean_message_body($input['body'] ?? null);

if ($body === null) {
    fail(422, 'Type a message first.');
}

$pdo = get_db();
ensure_messages_table($pdo);

if ($to <= 0 || !are_buddies($pdo, $userId, $to)) {
    fail(403, 'You can only message students you are buddies with.');
}

$pdo->prepare('INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)')
    ->execute([$userId, $to, $body]);

echo json_encode(['success' => true]);
