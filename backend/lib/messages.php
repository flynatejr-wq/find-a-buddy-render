<?php

const MESSAGE_MAX_LENGTH = 1000;

/** Returns the trimmed message text, or null if it is empty or not a string. */
function clean_message_body($body): ?string
{
    if (!is_string($body)) {
        return null;
    }
    $body = trim($body);
    if ($body === '') {
        return null;
    }
    return mb_substr($body, 0, MESSAGE_MAX_LENGTH);
}

/** Maps a request action from the client to a stored status, or null if invalid. */
function request_action_to_status($action): ?string
{
    if (!is_string($action)) {
        return null;
    }
    return ['accept' => 'accepted', 'decline' => 'declined'][$action] ?? null;
}

/** Creates the messages table if it does not exist yet (MySQL and Postgres). */
function ensure_messages_table(PDO $pdo): void
{
    $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id $id,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        body VARCHAR(1000) NOT NULL,
        is_read SMALLINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
    )");
}

/** True if the two users have an accepted buddy request between them. */
function are_buddies(PDO $pdo, int $a, int $b): bool
{
    $stmt = $pdo->prepare("
        SELECT 1 FROM buddy_requests
        WHERE status = 'accepted'
          AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
    ");
    $stmt->execute([$a, $b, $b, $a]);
    return (bool) $stmt->fetchColumn();
}
