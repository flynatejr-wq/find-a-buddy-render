<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';

require_login();
$_SESSION = [];
session_destroy();

echo json_encode(['success' => true]);
