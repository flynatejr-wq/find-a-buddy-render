<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/planner.php';

function fail(int $code, string $message, array $errors = []): void
{
    http_response_code($code);
    echo json_encode(['error' => $message, 'errors' => $errors]);
    exit;
}

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$pdo = get_db();
ensure_planner_tables($pdo);

// Remove one of my blocks.
if (!empty($input['remove_id'])) {
    $pdo->prepare('DELETE FROM schedule_blocks WHERE id = ? AND user_id = ?')->execute([(int) $input['remove_id'], $userId]);
    echo json_encode(['success' => true]);
    exit;
}

[$clean, $errors] = clean_block_input($input);
if ($errors) {
    fail(422, implode(' ', $errors), $errors);
}

// A class block has to be for a course I am taking now.
if ($clean['course_id'] !== null) {
    $stmt = $pdo->prepare('SELECT 1 FROM user_courses WHERE user_id = ? AND course_id = ?');
    $stmt->execute([$userId, $clean['course_id']]);
    if (!$stmt->fetchColumn()) {
        fail(404, 'That course is not in your current courses.');
    }
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM schedule_blocks WHERE user_id = ?');
$stmt->execute([$userId]);
if ((int) $stmt->fetchColumn() >= PLAN_MAX_BLOCKS) {
    fail(409, 'You can have up to ' . PLAN_MAX_BLOCKS . ' items in your schedule. Remove one first.');
}

$pdo->prepare('INSERT INTO schedule_blocks (user_id, course_id, label, day, start_min, end_min) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$userId, $clean['course_id'], $clean['label'], $clean['day'], $clean['start_min'], $clean['end_min']]);

echo json_encode(['success' => true]);
