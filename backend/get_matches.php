<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/availability.php';
require_once __DIR__ . '/lib/matching.php';
require_once __DIR__ . '/lib/profile.php';

$userId = require_login();
$pdo = get_db();
ensure_profiles_table($pdo);

// My courses.
$stmt = $pdo->prepare('SELECT course_id FROM user_courses WHERE user_id = ?');
$stmt->execute([$userId]);
$myCourseIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

if (!$myCourseIds) {
    echo json_encode(['matches' => [], 'has_courses' => false]);
    exit;
}

// Everyone else who shares at least one of those courses.
$in = implode(',', array_fill(0, count($myCourseIds), '?'));
$stmt = $pdo->prepare("
    SELECT uc.user_id, c.id AS course_id, c.course_code
    FROM user_courses uc
    JOIN courses c ON c.id = uc.course_id
    WHERE uc.course_id IN ($in) AND uc.user_id <> ?
    ORDER BY c.course_code
");
$stmt->execute(array_merge($myCourseIds, [$userId]));

$sharedByUser = [];
foreach ($stmt->fetchAll() as $row) {
    $sharedByUser[(int) $row['user_id']][] = ['id' => (int) $row['course_id'], 'course_code' => $row['course_code']];
}

if (!$sharedByUser) {
    echo json_encode(['matches' => [], 'has_courses' => true]);
    exit;
}

// My own profile + availability, for comparison.
$stmt = $pdo->prepare('SELECT u.availability, p.study_style, p.study_place
                       FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
$stmt->execute([$userId]);
$me = $stmt->fetch() ?: [];

// The candidates' public details (first name only, no email).
$ids = array_keys($sharedByUser);
$in = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("
    SELECT u.id, u.first_name, u.availability, p.major, p.year, p.study_times, p.study_style, p.study_place
    FROM users u LEFT JOIN profiles p ON p.user_id = u.id
    WHERE u.id IN ($in)
");
$stmt->execute($ids);

// Existing requests in either direction involving me.
$reqStmt = $pdo->prepare('SELECT sender_id, receiver_id, status FROM buddy_requests WHERE sender_id = ? OR receiver_id = ?');
$reqStmt->execute([$userId, $userId]);
$requestStatus = [];
foreach ($reqStmt->fetchAll() as $r) {
    $other = (int) $r['sender_id'] === $userId ? (int) $r['receiver_id'] : (int) $r['sender_id'];
    $requestStatus[$other] = ['status' => $r['status'], 'sent_by_me' => (int) $r['sender_id'] === $userId];
}

$matches = [];
foreach ($stmt->fetchAll() as $u) {
    $uid = (int) $u['id'];
    $shared = $sharedByUser[$uid];
    $slots = overlapping_availability((string) ($me['availability'] ?? ''), (string) ($u['availability'] ?? ''));
    $sameStyle = !empty($me['study_style']) && $me['study_style'] === ($u['study_style'] ?? null);
    $samePlace = !empty($me['study_place']) && $me['study_place'] === ($u['study_place'] ?? null);

    $matches[] = [
        'user_id' => $uid,
        'first_name' => $u['first_name'],
        'major' => $u['major'],
        'year' => $u['year'],
        'study_times' => $u['study_times'],
        'shared_courses' => $shared,
        'shared_slots' => $slots,
        'score' => match_score(count($shared), count($slots), $sameStyle, $samePlace),
        'request_status' => $requestStatus[$uid]['status'] ?? null,
        'request_sent_by_me' => $requestStatus[$uid]['sent_by_me'] ?? null,
    ];
}

usort($matches, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['first_name'], $b['first_name']));

echo json_encode(['matches' => $matches, 'has_courses' => true]);
