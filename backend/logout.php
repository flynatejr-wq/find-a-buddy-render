<?php

header('Content-Type: application/json');
require_once __DIR__ . '/session_bootstrap.php';

// Logging out must succeed even if the session is already invalid.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];
session_destroy();

echo json_encode(['success' => true]);
