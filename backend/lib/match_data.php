<?php

require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/matching.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/grades.php';

/** "?,?,?" for an IN (...) list. */
function match_placeholders(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

/**
 * Everyone who is a good study match for this student, best first.
 * Returns ['matches' => [...], 'has_courses' => bool]. Emails are never included.
 *
 * Three ways to be a match:
 *   1. classmates in a course we are both taking now;
 *   2. someone who passed (C or better) a course I am failing AND said they are open to helping;
 *   3. someone failing a course I passed AND I said I am open to helping.
 */
function compute_matches(PDO $pdo, int $userId): array
{
    ensure_profiles_table($pdo);
    ensure_standing_tables($pdo);

    $passedList = "'" . implode("','", PASSING_GRADES) . "'";

    // My current courses.
    $stmt = $pdo->prepare('SELECT course_id FROM user_courses WHERE user_id = ?');
    $stmt->execute([$userId]);
    $myCourseIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    // Current courses I am failing.
    $stmt = $pdo->prepare("
        SELECT p.course_id FROM course_progress p
        JOIN user_courses uc ON uc.user_id = p.user_id AND uc.course_id = p.course_id
        WHERE p.user_id = ? AND p.status = 'failing'
    ");
    $stmt->execute([$userId]);
    $myFailingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    // Courses I passed (C or better) and said I am open to helping with.
    $stmt = $pdo->prepare("SELECT course_id FROM course_grades WHERE user_id = ? AND grade IN ($passedList) AND available_to_help = 1");
    $stmt->execute([$userId]);
    $myHelpIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (!$myCourseIds && !$myHelpIds) {
        return ['matches' => [], 'has_courses' => false];
    }

    // 1. Classmates.
    $sharedByUser = [];
    if ($myCourseIds) {
        $stmt = $pdo->prepare('
            SELECT uc.user_id, c.id AS course_id, c.course_code
            FROM user_courses uc
            JOIN courses c ON c.id = uc.course_id
            WHERE uc.course_id IN (' . match_placeholders($myCourseIds) . ') AND uc.user_id <> ?
            ORDER BY c.course_code
        ');
        $stmt->execute(array_merge($myCourseIds, [$userId]));
        foreach ($stmt->fetchAll() as $row) {
            $sharedByUser[(int) $row['user_id']][] = ['id' => (int) $row['course_id'], 'course_code' => $row['course_code']];
        }
    }

    // 2. People who passed a course I am failing and are open to helping.
    $helpMeByUser = [];
    if ($myFailingIds) {
        $stmt = $pdo->prepare('
            SELECT user_id, course_id FROM course_grades
            WHERE course_id IN (' . match_placeholders($myFailingIds) . ") AND grade IN ($passedList) AND available_to_help = 1 AND user_id <> ?
        ");
        $stmt->execute(array_merge($myFailingIds, [$userId]));
        foreach ($stmt->fetchAll() as $row) {
            $helpMeByUser[(int) $row['user_id']][] = (int) $row['course_id'];
        }
    }

    // 3. People currently failing a course I can help with.
    $iHelpByUser = [];
    if ($myHelpIds) {
        $stmt = $pdo->prepare('
            SELECT p.user_id, p.course_id FROM course_progress p
            JOIN user_courses uc ON uc.user_id = p.user_id AND uc.course_id = p.course_id
            WHERE p.status = \'failing\' AND p.course_id IN (' . match_placeholders($myHelpIds) . ') AND p.user_id <> ?
        ');
        $stmt->execute(array_merge($myHelpIds, [$userId]));
        foreach ($stmt->fetchAll() as $row) {
            $iHelpByUser[(int) $row['user_id']][] = (int) $row['course_id'];
        }
    }

    $ids = array_values(array_unique(array_merge(array_keys($sharedByUser), array_keys($helpMeByUser), array_keys($iHelpByUser))));
    if (!$ids) {
        return ['matches' => [], 'has_courses' => true];
    }

    // Course codes for the tutoring links.
    $helpCourseIds = [];
    foreach ($helpMeByUser as $list) { $helpCourseIds = array_merge($helpCourseIds, $list); }
    foreach ($iHelpByUser as $list) { $helpCourseIds = array_merge($helpCourseIds, $list); }
    $codeById = [];
    if ($helpCourseIds) {
        $helpCourseIds = array_values(array_unique($helpCourseIds));
        $stmt = $pdo->prepare('SELECT id, course_code FROM courses WHERE id IN (' . match_placeholders($helpCourseIds) . ')');
        $stmt->execute($helpCourseIds);
        foreach ($stmt->fetchAll() as $row) {
            $codeById[(int) $row['id']] = $row['course_code'];
        }
    }
    $codes = fn(array $courseIds) => array_values(array_map(fn($id) => $codeById[$id], $courseIds));

    // My own profile + availability, for comparison.
    $stmt = $pdo->prepare('SELECT u.availability, p.study_style, p.study_place
                           FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = ?');
    $stmt->execute([$userId]);
    $me = $stmt->fetch() ?: [];

    // The candidates' public details (first name only, no email).
    $stmt = $pdo->prepare('
        SELECT u.id, u.first_name, u.availability, p.major, p.year, p.study_times, p.study_style, p.study_place
        FROM users u LEFT JOIN profiles p ON p.user_id = u.id
        WHERE u.id IN (' . match_placeholders($ids) . ')
    ');
    $stmt->execute($ids);
    $candidates = $stmt->fetchAll();

    // Existing requests in either direction involving me.
    $reqStmt = $pdo->prepare('SELECT sender_id, receiver_id, status FROM buddy_requests WHERE sender_id = ? OR receiver_id = ?');
    $reqStmt->execute([$userId, $userId]);
    $requestStatus = [];
    foreach ($reqStmt->fetchAll() as $r) {
        $other = (int) $r['sender_id'] === $userId ? (int) $r['receiver_id'] : (int) $r['sender_id'];
        $requestStatus[$other] = ['status' => $r['status'], 'sent_by_me' => (int) $r['sender_id'] === $userId];
    }

    $matches = [];
    foreach ($candidates as $u) {
        $uid = (int) $u['id'];
        $shared = $sharedByUser[$uid] ?? [];
        $helpMe = $helpMeByUser[$uid] ?? [];
        $iHelp = $iHelpByUser[$uid] ?? [];
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
            'can_help_you' => $codes($helpMe),
            'you_can_help' => $codes($iHelp),
            'score' => match_score(count($shared), count($slots), $sameStyle, $samePlace, (bool) $helpMe, (bool) $iHelp),
            'request_status' => $requestStatus[$uid]['status'] ?? null,
            'request_sent_by_me' => $requestStatus[$uid]['sent_by_me'] ?? null,
        ];
    }

    usort($matches, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['first_name'], $b['first_name']));

    return ['matches' => $matches, 'has_courses' => true];
}
