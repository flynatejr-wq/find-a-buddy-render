<?php

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        try {
            $databaseUrl = getenv('DATABASE_URL');

            if ($databaseUrl) {
                // Render-style Postgres connection string: postgres://user:pass@host:port/dbname
                // (Render sometimes omits the port when it's the Postgres default)
                $parts = parse_url($databaseUrl);
                $port = $parts['port'] ?? 5432;
                $dsn = "pgsql:host={$parts['host']};port={$port};dbname=" . ltrim($parts['path'], '/');
                $pdo = new PDO($dsn, $parts['user'], $parts['pass'], $options);
            } else {
                $config = require __DIR__ . '/config.php';
                $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], $options);
            }
        } catch (PDOException $e) {
            // Log the real reason, but never show visitors server details such as host names or file paths.
            error_log('Database connection failed: ' . $e->getMessage());
            if (PHP_SAPI === 'cli') {
                throw $e;
            }
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: application/json');
            }
            echo json_encode(['error' => 'The database is unavailable right now. Please try again later.']);
            exit;
        }
    }
    return $pdo;
}

function db_is_postgres(): bool
{
    return get_db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
}
