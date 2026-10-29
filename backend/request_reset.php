<?php

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/validation.php';
require_once __DIR__ . '/lib/reset.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = normalize_email((string) ($input['email'] ?? ''));

// Without an email service there is nothing to send. Say so plainly; this does
// not depend on whether the account exists, so it reveals nothing about accounts.
if (!mail_is_configured()) {
    echo json_encode([
        'success' => true,
        'emailed' => false,
        'message' => 'Password reset emails are not set up yet. Ask the site admin to send you a reset link.',
    ]);
    exit;
}

$pdo = get_db();
ensure_resets_table($pdo);

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$userId = $stmt->fetchColumn();

if ($userId) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?');
    $stmt->execute([$userId, time() - 3600]);
    if ((int) $stmt->fetchColumn() < RESET_MAX_PER_HOUR) {
        $token = create_reset_token($pdo, (int) $userId);
        send_reset_email($email, reset_link(app_base_url(), $token));
    }
}

// Same answer whether or not the account exists.
echo json_encode([
    'success' => true,
    'emailed' => true,
    'message' => 'If that email has an account, a reset link is on its way. It works once and expires in 1 hour.',
]);
