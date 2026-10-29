<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/profile.php';
require_once __DIR__ . '/lib/messages.php';
require_once __DIR__ . '/lib/sessions.php';

$userId = require_login();
$pdo = get_db();
ensure_profiles_table($pdo);
ensure_messages_table($pdo);

// Accepted buddies. Contact info (email) is only revealed here, after acceptance.
$stmt = $pdo->prepare("
    SELECT r.id AS request_id, u.id AS user_id, u.first_name, u.last_name, u.email,
           p.major, p.year, c.course_code
    FROM buddy_requests r
    JOIN users u ON u.id = CASE WHEN r.sender_id = ? THEN r.receiver_id ELSE r.sender_id END
    LEFT JOIN profiles p ON p.user_id = u.id
    LEFT JOIN courses c ON c.id = r.course_id
    WHERE r.status = 'accepted' AND (r.sender_id = ? OR r.receiver_id = ?)
    ORDER BY u.first_name
");
$stmt->execute([$userId, $userId, $userId]);
$buddies = $stmt->fetchAll();

$lastStmt = $pdo->prepare('
    SELECT body, sender_id, created_at FROM messages
    WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
    ORDER BY id DESC LIMIT 1
');
$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sender_id = ? AND receiver_id = ? AND is_read = 0');
$totalUnread = 0;
foreach ($buddies as &$b) {
    $bid = (int) $b['user_id'];
    $lastStmt->execute([$userId, $bid, $bid, $userId]);
    $last = $lastStmt->fetch();
    $b['last_message'] = $last ? mb_substr($last['body'], 0, 80) : null;
    $b['last_message_at'] = $last['created_at'] ?? null;
    $unreadStmt->execute([$bid, $userId]);
    $b['unread'] = (int) $unreadStmt->fetchColumn();
    $totalUnread += $b['unread'];
}
unset($b);

// Requests waiting for my answer (first name + major/year only, no email yet).
$stmt = $pdo->prepare("
    SELECT r.id AS request_id, u.id AS user_id, u.first_name, p.major, p.year, c.course_code, r.created_at
    FROM buddy_requests r
    JOIN users u ON u.id = r.sender_id
    LEFT JOIN profiles p ON p.user_id = u.id
    LEFT JOIN courses c ON c.id = r.course_id
    WHERE r.receiver_id = ? AND r.status = 'pending'
    ORDER BY r.id DESC
");
$stmt->execute([$userId]);
$incoming = $stmt->fetchAll();

// Requests I sent that have not been answered yet.
$stmt = $pdo->prepare("
    SELECT r.id AS request_id, u.first_name, c.course_code, r.created_at
    FROM buddy_requests r
    JOIN users u ON u.id = r.receiver_id
    LEFT JOIN courses c ON c.id = r.course_id
    WHERE r.sender_id = ? AND r.status = 'pending'
    ORDER BY r.id DESC
");
$stmt->execute([$userId]);
$sent = $stmt->fetchAll();

// Study sessions a buddy proposed that I have not answered yet.
ensure_sessions_table($pdo);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM study_sessions WHERE buddy_id = ? AND status = 'proposed' AND session_date >= ?");
$stmt->execute([$userId, campus_today()]);

echo json_encode([
    'buddies' => $buddies,
    'incoming' => $incoming,
    'sent' => $sent,
    'unread_messages' => $totalUnread,
    'pending_sessions' => (int) $stmt->fetchColumn(),
]);
