<?php

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/account.php';
require_once __DIR__ . '/lib/reset.php';

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$token = (string) ($input['token'] ?? '');
$new = (string) ($input['password'] ?? '');

if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    fail(400, 'This reset link is not valid.');
}
if (($error = validate_new_password($new, '')) !== null) {
    fail(422, $error);
}

$pdo = get_db();
ensure_resets_table($pdo);

$stmt = $pdo->prepare('SELECT id, user_id, expires_at, used FROM password_resets WHERE token_hash = ?');
$stmt->execute([hash_reset_token($token)]);
$row = $stmt->fetch();

if (!$row || !reset_is_usable($row, time())) {
    fail(400, 'This reset link has expired or was already used. Ask for a new one.');
}

$pdo->beginTransaction();
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([password_hash($new, PASSWORD_DEFAULT), $row['user_id']]);
// Retire this link and any other outstanding ones for the account.
$pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ?')->execute([$row['user_id']]);
$pdo->commit();

echo json_encode(['success' => true]);
