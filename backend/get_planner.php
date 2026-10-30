<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/planner_data.php';

$userId = require_login();

echo json_encode(compute_planner_data(get_db(), $userId));
