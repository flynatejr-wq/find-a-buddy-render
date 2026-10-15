<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/profile.php';

$userId = require_login();
$pdo = get_db();
ensure_profiles_table($pdo);

$stmt = $pdo->prepare('SELECT major, year, study_times, study_style, study_place, help_text FROM profiles WHERE user_id = ?');
$stmt->execute([$userId]);
$profile = $stmt->fetch() ?: null;

echo json_encode(['profile' => $profile]);
