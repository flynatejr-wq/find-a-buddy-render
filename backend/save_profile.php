<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/profile.php';

$userId = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

[$clean, $errors] = clean_profile_input($input);
if ($errors) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errors), 'errors' => $errors]);
    exit;
}

$pdo = get_db();
ensure_profiles_table($pdo);

$sql = db_is_postgres()
    ? 'INSERT INTO profiles (user_id, major, year, study_times, study_style, study_place, help_text)
       VALUES (?, ?, ?, ?, ?, ?, ?)
       ON CONFLICT (user_id) DO UPDATE SET major = EXCLUDED.major, year = EXCLUDED.year,
         study_times = EXCLUDED.study_times, study_style = EXCLUDED.study_style,
         study_place = EXCLUDED.study_place, help_text = EXCLUDED.help_text, updated_at = CURRENT_TIMESTAMP'
    : 'INSERT INTO profiles (user_id, major, year, study_times, study_style, study_place, help_text)
       VALUES (?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE major = VALUES(major), year = VALUES(year),
         study_times = VALUES(study_times), study_style = VALUES(study_style),
         study_place = VALUES(study_place), help_text = VALUES(help_text), updated_at = CURRENT_TIMESTAMP';

$pdo->prepare($sql)->execute([
    $userId, $clean['major'], $clean['year'], $clean['study_times'],
    $clean['study_style'], $clean['study_place'], $clean['help_text'],
]);

echo json_encode(['success' => true]);
