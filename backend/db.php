<?php

/**
 * On PostgreSQL, creates the app's tables the first time it connects to an empty database, so
 * moving to a new database only means pointing DATABASE_URL at it. Does nothing if the tables exist.
 */
function ensure_core_schema(PDO $pdo): void
{
    if ($pdo->query("SELECT to_regclass('users')")->fetchColumn() !== null) {
        return;
    }

    // If two visitors arrive at once on an empty database, only one of them builds the tables.
    $pdo->query('SELECT pg_advisory_lock(7272001)');
    try {
        if ($pdo->query("SELECT to_regclass('users')")->fetchColumn() === null) {
            $sql = file_get_contents(__DIR__ . '/schema.postgres.sql');
            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    } finally {
        $pdo->query('SELECT pg_advisory_unlock(7272001)');
    }
}

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
                // Postgres connection string: postgres://user:pass@host:port/dbname[?sslmode=require]
                // (Render sometimes omits the port when it's the Postgres default)
                $parts = parse_url($databaseUrl);
                $port = $parts['port'] ?? 5432;
                $dsn = "pgsql:host={$parts['host']};port={$port};dbname=" . ltrim($parts['path'], '/');

                // Some hosts (for example Neon and Supabase) require an encrypted connection.
                parse_str($parts['query'] ?? '', $query);
                if (!empty($query['sslmode']) && preg_match('/^[a-z-]+$/', $query['sslmode'])) {
                    $dsn .= ';sslmode=' . $query['sslmode'];
                }

                $pdo = new PDO($dsn, urldecode($parts['user'] ?? ''), urldecode($parts['pass'] ?? ''), $options);
                ensure_core_schema($pdo);
            } else {
                $config = require __DIR__ . '/config.php';
                $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], $options);
            }
        } catch (PDOException $e) {
            // Log the real reason, but never show visitors server details such as host names or file paths.
            error_log('Database connection failed: ' . $e->getMessage());
            $pdo = null;
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
