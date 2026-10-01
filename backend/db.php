<?php

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $databaseUrl = getenv('DATABASE_URL');

        if ($databaseUrl) {
            // Render-style Postgres connection string: postgres://user:pass@host:port/dbname
            // (Render sometimes omits the port when it's the Postgres default)
            $parts = parse_url($databaseUrl);
            $port = $parts['port'] ?? 5432;
            $dsn = "pgsql:host={$parts['host']};port={$port};dbname=" . ltrim($parts['path'], '/');
            $pdo = new PDO($dsn, $parts['user'], $parts['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            $config = require __DIR__ . '/config.php';
            $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
    }
    return $pdo;
}

function db_is_postgres(): bool
{
    return get_db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
}
