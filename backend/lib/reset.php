<?php

const RESET_TTL_SECONDS = 3600;
const RESET_MAX_PER_HOUR = 3;

function hash_reset_token(string $token): string
{
    return hash('sha256', $token);
}

/** Returns [rawToken, tokenHash]. Only the hash is ever stored. */
function new_reset_token(): array
{
    $token = bin2hex(random_bytes(32));
    return [$token, hash_reset_token($token)];
}

/** A stored reset row is usable if it has not been used and has not expired. */
function reset_is_usable(array $row, int $now): bool
{
    return empty($row['used']) && (int) $row['expires_at'] > $now;
}

function reset_link(string $baseUrl, string $token): string
{
    return rtrim($baseUrl, '/') . '/frontend/index.html?reset=' . urlencode($token);
}

/** The public address of the site, used in reset emails. Never taken from the request. */
function app_base_url(): string
{
    return rtrim((string) getenv('APP_URL'), '/');
}

function mail_is_configured(): bool
{
    return getenv('RESEND_API_KEY') && getenv('MAIL_FROM') && app_base_url() !== '';
}

function ensure_resets_table(PDO $pdo): void
{
    $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id $id,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at BIGINT NOT NULL,
        used SMALLINT NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
}

/** Stores a new reset token for the user and returns the raw token (shown or sent once). */
function create_reset_token(PDO $pdo, int $userId): string
{
    [$token, $hash] = new_reset_token();
    $now = time();
    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $hash, $now + RESET_TTL_SECONDS, $now]);
    return $token;
}

/** Sends the reset link through Resend (https://resend.com). Returns true if the API accepted it. */
function send_reset_email(string $to, string $link): bool
{
    $payload = json_encode([
        'from' => getenv('MAIL_FROM'),
        'to' => [$to],
        'subject' => 'Reset your Find a Buddy password',
        'text' => "Use this link to choose a new password. It works once and expires in 1 hour.\n\n$link\n\nIf you did not ask for this, you can ignore this email.",
    ]);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . getenv('RESEND_API_KEY') . "\r\n",
        'content' => $payload,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $result = @file_get_contents('https://api.resend.com/emails', false, $context);
    if ($result === false || empty($http_response_header[0])) {
        return false;
    }
    return (bool) preg_match('#\s2\d\d\s#', $http_response_header[0]);
}
