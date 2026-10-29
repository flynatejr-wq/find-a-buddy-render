<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/sessions.php';

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$sessionId = (int) ($input['session_id'] ?? 0);
$action = $input['action'] ?? null;

$pdo = get_db();
ensure_sessions_table($pdo);

$stmt = $pdo->prepare('SELECT proposer_id, buddy_id, status FROM study_sessions WHERE id = ?');
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

// Treat other people's sessions as not found.
if (!$session || ((int) $session['proposer_id'] !== $userId && (int) $session['buddy_id'] !== $userId)) {
    fail(404, 'That session does not exist.');
}

$next = session_next_status($action, (int) $session['proposer_id'] === $userId, $session['status']);
if ($next === null) {
    fail(409, 'That is not possible for this session right now.');
}

$pdo->prepare('UPDATE study_sessions SET status = ? WHERE id = ?')->execute([$next, $sessionId]);

echo json_encode(['success' => true, 'status' => $next]);
