<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/messages.php';

$userId = require_login();
$otherId = (int) ($_GET['with'] ?? 0);

$pdo = get_db();
ensure_messages_table($pdo);

if ($otherId <= 0 || !are_buddies($pdo, $userId, $otherId)) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only message students you are buddies with.']);
    exit;
}

$stmt = $pdo->prepare('SELECT first_name FROM users WHERE id = ?');
$stmt->execute([$otherId]);
$name = (string) $stmt->fetchColumn();

$stmt = $pdo->prepare('
    SELECT id, sender_id, body, created_at FROM (
        SELECT id, sender_id, body, created_at FROM messages
        WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
        ORDER BY id DESC LIMIT 200
    ) recent ORDER BY id ASC
');
$stmt->execute([$userId, $otherId, $otherId, $userId]);
$rows = $stmt->fetchAll();

$messages = array_map(fn($m) => [
    'id' => (int) $m['id'],
    'mine' => (int) $m['sender_id'] === $userId,
    'body' => $m['body'],
    'created_at' => $m['created_at'],
], $rows);

// Opening the thread marks what they sent me as read.
$pdo->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0')
    ->execute([$otherId, $userId]);

echo json_encode(['with' => ['user_id' => $otherId, 'first_name' => $name], 'messages' => $messages]);
