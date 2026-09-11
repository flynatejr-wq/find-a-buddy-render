<?php

require_once __DIR__ . '/session_bootstrap.php';

function require_login(): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not authenticated.']);
        exit;
    }
    return (int) $_SESSION['user_id'];
}
