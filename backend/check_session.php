<?php

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';

function reject_unauthenticated(): void
{
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

function require_login(): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        reject_unauthenticated();
    }

    $userId = (int) $_SESSION['user_id'];

    // The session must point at a real account; a session for a deleted
    // user is not a valid login.
    $stmt = get_db()->prepare('SELECT 1 FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    if (!$stmt->fetchColumn()) {
        $_SESSION = [];
        session_destroy();
        reject_unauthenticated();
    }

    return $userId;
}
