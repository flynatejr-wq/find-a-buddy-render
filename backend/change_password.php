<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/account.php';

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$current = (string) ($input['current_password'] ?? '');
$new = (string) ($input['new_password'] ?? '');

$pdo = get_db();
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$userId]);
$hash = (string) $stmt->fetchColumn();

if (!password_verify($current, $hash)) {
    fail(403, 'Your current password is not correct.');
}
if (($error = validate_new_password($new, $current)) !== null) {
    fail(422, $error);
}

$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
session_regenerate_id(true);

echo json_encode(['success' => true]);
