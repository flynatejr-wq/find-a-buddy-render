<?php

header('Content-Type: application/json');
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/ai.php';

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;

function fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

$userId = require_login();

if (!ai_sdk_installed()) {
    fail(503, 'The AI assistant is missing its libraries. The site owner needs to run composer install.');
}
if (!ai_is_configured()) {
    fail(503, 'The AI assistant is not switched on yet. The site owner needs to add an Anthropic API key.');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/ai_tools.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$message = ai_clean_user_message($input['message'] ?? null);
if ($message === null) {
    fail(422, 'Type a message first.');
}

$pdo = get_db();
$usage = ai_usage_today($pdo, $userId);
$limits = ai_limits();
if ($usage['user'] >= $limits['user']) {
    fail(429, "You have used your {$limits['user']} messages with Rowdy for today. Come back tomorrow!");
}
if ($usage['global'] >= $limits['global']) {
    fail(429, 'Rowdy is very busy today. Please try again tomorrow.');
}

set_time_limit(150);

try {
    $client = new Anthropic\Client(apiKey: ai_api_key(), requestOptions: ['timeout' => 60.0, 'maxRetries' => 1]);
    $result = ai_handle_chat($pdo, $userId, $message, $client);
} catch (AuthenticationException $e) {
    error_log('AI: the API key was rejected: ' . $e->getMessage());
    fail(502, 'The AI assistant could not sign in. Tell the site owner to check the API key.');
} catch (RateLimitException $e) {
    fail(503, 'Rowdy is busy right now. Try again in a minute.');
} catch (APITimeoutException $e) {
    fail(504, 'Rowdy took too long to answer. Try a simpler question.');
} catch (APIConnectionException $e) {
    error_log('AI: connection problem: ' . $e->getMessage());
    fail(502, 'Could not reach the AI service. Try again in a moment.');
} catch (APIStatusException $e) {
    error_log('AI: API error: ' . $e->getMessage());
    fail(502, 'The AI service had a problem. Try again in a moment.');
} catch (Throwable $e) {
    error_log('AI: unexpected error: ' . $e->getMessage());
    fail(500, 'Something went wrong. Try again.');
}

$result['remaining_today'] = max(0, $limits['user'] - ($usage['user'] + 1));
echo json_encode($result);
