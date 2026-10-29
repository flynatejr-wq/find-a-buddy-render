<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/planner.php';

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

[$clean, $errors] = clean_planner_prefs($input);
if ($errors) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errors), 'errors' => $errors]);
    exit;
}

$pdo = get_db();
ensure_planner_tables($pdo);

$sql = db_is_postgres()
    ? 'INSERT INTO planner_settings (user_id, hours, day_start, day_end) VALUES (?, ?, ?, ?)
       ON CONFLICT (user_id) DO UPDATE SET hours = EXCLUDED.hours, day_start = EXCLUDED.day_start, day_end = EXCLUDED.day_end'
    : 'INSERT INTO planner_settings (user_id, hours, day_start, day_end) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE hours = VALUES(hours), day_start = VALUES(day_start), day_end = VALUES(day_end)';
$pdo->prepare($sql)->execute([$userId, $clean['hours'], $clean['day_start'], $clean['day_end']]);

echo json_encode(['success' => true]);
