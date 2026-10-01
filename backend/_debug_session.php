<?php

header('Content-Type: application/json');
require_once __DIR__ . '/session_bootstrap.php';

session_start();

$savePath = ini_get('session.save_path');
$effectivePath = $savePath !== '' ? $savePath : sys_get_temp_dir();

$_SESSION['hits'] = ($_SESSION['hits'] ?? 0) + 1;

echo json_encode([
    'session_id' => session_id(),
    'hits_this_session' => $_SESSION['hits'],
    'session_save_path_ini' => $savePath,
    'effective_path' => $effectivePath,
    'path_exists' => is_dir($effectivePath),
    'path_writable' => is_writable($effectivePath),
    'cookie_sent_by_browser' => $_COOKIE['PHPSESSID'] ?? null,
    'session_save_handler' => ini_get('session.save_handler'),
    'php_sapi' => php_sapi_name(),
], JSON_PRETTY_PRINT);
