<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/sessions.php';
require_once __DIR__ . '/lib/messages.php';

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$buddyId = (int) ($input['buddy_id'] ?? 0);

$pdo = get_db();
ensure_sessions_table($pdo);

if ($buddyId <= 0 || !are_buddies($pdo, $userId, $buddyId)) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only plan sessions with your buddies.']);
    exit;
}

[$clean, $errors] = clean_session_input($input, campus_today());
if ($errors) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errors), 'errors' => $errors]);
    exit;
}

$pdo->prepare('INSERT INTO study_sessions (proposer_id, buddy_id, topic, session_date, session_time, place, note) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([$userId, $buddyId, $clean['topic'], $clean['session_date'], $clean['session_time'], $clean['place'], $clean['note']]);

echo json_encode(['success' => true]);
