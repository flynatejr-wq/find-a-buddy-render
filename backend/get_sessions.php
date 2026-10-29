<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/sessions.php';

$userId = require_login();
$pdo = get_db();
ensure_sessions_table($pdo);

// Live sessions (proposed or accepted) that have not passed yet, soonest first.
$stmt = $pdo->prepare("
    SELECT s.id, s.proposer_id, s.topic, s.session_date, s.session_time, s.place, s.note, s.status,
           u.id AS other_id, u.first_name AS other_first_name
    FROM study_sessions s
    JOIN users u ON u.id = CASE WHEN s.proposer_id = ? THEN s.buddy_id ELSE s.proposer_id END
    WHERE (s.proposer_id = ? OR s.buddy_id = ?)
      AND s.status IN ('proposed', 'accepted')
      AND s.session_date >= ?
    ORDER BY s.session_date, s.session_time
");
$stmt->execute([$userId, $userId, $userId, campus_today()]);

$sessions = array_map(fn($s) => [
    'id' => (int) $s['id'],
    'mine' => (int) $s['proposer_id'] === $userId,
    'other_id' => (int) $s['other_id'],
    'other_first_name' => $s['other_first_name'],
    'topic' => $s['topic'],
    'session_date' => $s['session_date'],
    'session_time' => $s['session_time'],
    'place' => $s['place'],
    'note' => $s['note'],
    'status' => $s['status'],
], $stmt->fetchAll());

echo json_encode(['sessions' => $sessions, 'places' => SESSION_PLACES, 'today' => campus_today()]);
