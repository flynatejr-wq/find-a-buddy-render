<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/planner_data.php';

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

[$clean, $errors] = clean_planner_prefs($input);
if ($errors) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errors), 'errors' => $errors]);
    exit;
}

save_planner_prefs(get_db(), $userId, $clean);

echo json_encode(['success' => true]);
