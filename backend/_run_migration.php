<?php

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

// Temporary one-time migration runner. Requires a shared secret so random
// visitors can't trigger it. Delete this file once the schema is created.
$secret = $_GET['key'] ?? '';
if ($secret !== 'findabuddy-migrate-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$pdo = get_db();
$schemaFile = db_is_postgres() ? 'schema.postgres.sql' : 'schema.sql';
$sql = file_get_contents(__DIR__ . '/' . $schemaFile);

$statements = array_filter(array_map('trim', explode(';', $sql)));
$results = [];

foreach ($statements as $statement) {
    if ($statement === '') {
        continue;
    }
    try {
        $pdo->exec($statement);
        $results[] = ['statement' => substr($statement, 0, 60) . '...', 'status' => 'ok'];
    } catch (PDOException $e) {
        $results[] = ['statement' => substr($statement, 0, 60) . '...', 'status' => 'error', 'message' => $e->getMessage()];
    }
}

echo json_encode(['results' => $results], JSON_PRETTY_PRINT);
