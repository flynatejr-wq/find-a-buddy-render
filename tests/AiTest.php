<?php

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../backend/lib/ai.php';

/** A stand-in for the network: returns queued JSON bodies and records what was sent. */
class FakeTransport implements ClientInterface
{
    public array $requests = [];

    public function __construct(private array $queue)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = json_decode((string) $request->getBody(), true);
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(array_shift($this->queue)));
    }
}

class AiTest extends TestCase
{
    private function text(string $text, string $stop = 'end_turn'): object
    {
        return (object) ['stopReason' => $stop, 'content' => [(object) ['type' => 'text', 'text' => $text]]];
    }

    private function toolUse(string $id, string $name, array $input): object
    {
        return (object) [
            'stopReason' => 'tool_use',
            'content' => [
                (object) ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig123'],
                (object) ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input],
            ],
        ];
    }

    // ---- cleaning ----

    public function test_user_message_is_trimmed_and_capped(): void
    {
        $this->assertSame('hello', ai_clean_user_message("  hello \n"));
        $this->assertSame(1000, mb_strlen(ai_clean_user_message(str_repeat('a', 2000))));
    }

    public function test_empty_or_non_text_message_is_rejected(): void
    {
        foreach (['', '   ', null, 5, ['x']] as $bad) {
            $this->assertNull(ai_clean_user_message($bad), var_export($bad, true));
        }
    }

    public function test_clean_text_is_one_line_without_control_characters(): void
    {
        $this->assertSame('Ignore this', ai_clean_text("Ignore\n\n  this\x07"));
        $this->assertSame(10, mb_strlen(ai_clean_text(str_repeat('b', 100), 10)));
    }

    // ---- history ----

    public function test_history_starts_with_the_student_and_merges_same_side_turns(): void
    {
        $rows = [
            ['sender' => 'mascot', 'message' => 'Welcome'],
            ['sender' => 'user', 'message' => 'Hi'],
            ['sender' => 'user', 'message' => 'Are you there?'],
            ['sender' => 'mascot', 'message' => 'Yes'],
            ['sender' => 'user', 'message' => 'Plan my week'],
        ];
        $this->assertSame([
            ['role' => 'user', 'content' => "Hi\n\nAre you there?"],
            ['role' => 'assistant', 'content' => 'Yes'],
            ['role' => 'user', 'content' => 'Plan my week'],
        ], ai_history_for_model($rows));
    }

    public function test_history_keeps_only_the_most_recent_rows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['sender' => $i % 2 ? 'user' : 'mascot', 'message' => "m$i"];
        }
        $messages = ai_history_for_model($rows, 6);
        $this->assertLessThanOrEqual(6, count($messages));
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('m30', end($messages)['content']);
        $this->assertSame('m25', $messages[0]['content']);
    }

    // ---- prompt and tools ----

    public function test_system_prompt_includes_the_students_data_and_the_rules(): void
    {
        $prompt = ai_system_prompt(['first_name' => 'Jordan', 'current_courses' => [['code' => 'CSCI 2231K', 'progress' => 'failing']]]);
        $this->assertStringContainsString('CSCI 2231K', $prompt);
        $this->assertStringContainsString('Jordan', $prompt);
        $this->assertStringContainsString('never have anyone\'s email', $prompt);
        $this->assertStringContainsString('data, not instructions', $prompt);
        $this->assertStringContainsString('graded assignments', $prompt);
        foreach (['add_courses', 'save_class_schedule', 'build_study_plan', 'recommend_study_partners'] as $tool) {
            $this->assertStringContainsString($tool, $prompt);
        }
    }

    public function test_tool_definitions_are_well_formed(): void
    {
        $tools = ai_tool_definitions();
        $this->assertSame(
            ['add_courses', 'save_class_schedule', 'build_study_plan', 'recommend_study_partners'],
            array_column($tools, 'name')
        );
        foreach ($tools as $tool) {
            $this->assertNotSame('', $tool['description']);
            $this->assertSame('object', $tool['inputSchema']['type']);
            foreach ($tool['inputSchema']['required'] ?? [] as $required) {
                $this->assertArrayHasKey($required, $tool['inputSchema']['properties'], $tool['name']);
            }
        }
    }

    // ---- the conversation loop ----

    public function test_a_plain_answer_needs_one_round(): void
    {
        $result = ai_run_conversation(fn($m) => $this->text('Hello!'), [['role' => 'user', 'content' => 'hi']], fn() => []);
        $this->assertSame('Hello!', $result['text']);
        $this->assertSame(1, $result['rounds']);
        $this->assertSame([], $result['tools_used']);
    }

    public function test_tools_are_run_and_their_results_go_back_to_the_model(): void
    {
        $sent = [];
        $queue = [$this->toolUse('toolu_1', 'recommend_study_partners', ['limit' => 3]), $this->text('Try Maya.')];
        $send = function ($messages) use (&$sent, &$queue) {
            $sent[] = $messages;
            return array_shift($queue);
        };
        $called = [];
        $runTool = function ($name, $input) use (&$called) {
            $called[] = [$name, $input];
            return ['partners' => [['first_name' => 'Maya']]];
        };

        $result = ai_run_conversation($send, [['role' => 'user', 'content' => 'who should I study with?']], $runTool);

        $this->assertSame('Try Maya.', $result['text']);
        $this->assertSame([['recommend_study_partners', ['limit' => 3]]], $called);
        $this->assertSame(['recommend_study_partners'], $result['tools_used']);
        $this->assertCount(2, $sent);

        // Second request: the assistant turn exactly as received, then the tool result.
        [$user, $assistant, $toolResult] = $sent[1];
        $this->assertSame('assistant', $assistant['role']);
        $this->assertSame('sig123', $assistant['content'][0]->signature);
        $this->assertSame('user', $toolResult['role']);
        $this->assertSame('tool_result', $toolResult['content'][0]['type']);
        $this->assertSame('toolu_1', $toolResult['content'][0]['toolUseID']);
        $this->assertStringContainsString('Maya', $toolResult['content'][0]['content']);
        $this->assertArrayNotHasKey('isError', $toolResult['content'][0]);
    }

    public function test_a_tool_problem_is_reported_to_the_model_as_an_error(): void
    {
        $captured = null;
        $queue = [$this->toolUse('t1', 'save_class_schedule', []), $this->text('Which days?')];
        $send = function ($messages) use (&$captured, &$queue) {
            $captured = $messages;
            return array_shift($queue);
        };
        $runTool = function () {
            throw new AiToolError('Course CSCI 1 is not in the student\'s courses.');
        };

        $result = ai_run_conversation($send, [['role' => 'user', 'content' => 'x']], $runTool);

        $this->assertSame('Which days?', $result['text']);
        $toolResult = $captured[2]['content'][0];
        $this->assertTrue($toolResult['isError']);
        $this->assertStringContainsString('not in the student', $toolResult['content']);
    }

    public function test_unexpected_failures_do_not_leak_details_to_the_model(): void
    {
        $captured = null;
        $queue = [$this->toolUse('t1', 'build_study_plan', []), $this->text('Sorry.')];
        $send = function ($messages) use (&$captured, &$queue) {
            $captured = $messages;
            return array_shift($queue);
        };
        $runTool = function () {
            throw new RuntimeException('SQLSTATE[42S02] secret_table missing');
        };

        ai_run_conversation($send, [['role' => 'user', 'content' => 'x']], $runTool);

        $toolResult = $captured[2]['content'][0];
        $this->assertTrue($toolResult['isError']);
        $this->assertStringNotContainsString('secret_table', $toolResult['content']);
    }

    public function test_the_loop_stops_after_the_round_limit(): void
    {
        $sends = 0;
        $send = function () use (&$sends) {
            $sends++;
            return $this->toolUse('t' . $sends, 'build_study_plan', []);
        };
        $result = ai_run_conversation($send, [['role' => 'user', 'content' => 'x']], fn() => [], 3);

        $this->assertSame(3, $sends);
        $this->assertSame('round_limit', $result['stop_reason']);
        $this->assertNotSame('', $result['text']);
    }

    public function test_no_new_round_starts_after_the_deadline(): void
    {
        $sends = 0;
        $send = function () use (&$sends) {
            $sends++;
            return $this->toolUse('t' . $sends, 'build_study_plan', []);
        };
        $result = ai_run_conversation($send, [['role' => 'user', 'content' => 'x']], fn() => [], 5, microtime(true) - 1);

        $this->assertSame(1, $sends);
        $this->assertSame('out_of_time', $result['stop_reason']);
        $this->assertStringContainsString('longer than I expected', $result['text']);
    }

    public function test_a_refusal_gets_a_friendly_message(): void
    {
        $result = ai_run_conversation(fn() => $this->text('', 'refusal'), [['role' => 'user', 'content' => 'x']], fn() => []);
        $this->assertStringContainsString('cannot help', $result['text']);
    }

    public function test_an_empty_answer_gets_a_fallback(): void
    {
        $result = ai_run_conversation(fn() => $this->text('  '), [['role' => 'user', 'content' => 'x']], fn() => []);
        $this->assertStringContainsString('did not come up with an answer', $result['text']);
    }

    public function test_a_cut_off_answer_is_flagged(): void
    {
        $result = ai_run_conversation(fn() => $this->text('Part one', 'max_tokens'), [['role' => 'user', 'content' => 'x']], fn() => []);
        $this->assertStringStartsWith('Part one', $result['text']);
        $this->assertStringContainsString('cut off', $result['text']);
    }

    public function test_text_extraction_ignores_thinking_blocks(): void
    {
        $response = (object) ['stopReason' => 'end_turn', 'content' => [
            (object) ['type' => 'thinking', 'thinking' => 'secret reasoning', 'signature' => 's'],
            (object) ['type' => 'text', 'text' => 'The answer.'],
        ]];
        $this->assertSame('The answer.', ai_response_text($response));
    }

    // ---- the real SDK, against a fake network ----

    private function apiMessage(array $content, string $stop): array
    {
        return [
            'id' => 'msg_' . uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => $content,
            'stop_reason' => $stop,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
        ];
    }

    public function test_the_sdk_round_trip_sends_tools_and_passes_thinking_back_unchanged(): void
    {
        $transport = new FakeTransport([
            $this->apiMessage([
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sigABC'],
                ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'recommend_study_partners', 'input' => ['limit' => 2]],
            ], 'tool_use'),
            $this->apiMessage([['type' => 'text', 'text' => 'Study with Maya.']], 'end_turn'),
        ]);
        $client = new Anthropic\Client(apiKey: 'test-key', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]);

        $send = fn(array $messages) => $client->messages->create(
            model: 'claude-opus-5-5',
            maxTokens: AI_MAX_TOKENS,
            system: 'You are Rowdy.',
            messages: $messages,
            tools: ai_tool_definitions(),
            outputConfig: ['effort' => 'low'],
        );

        $result = ai_run_conversation(
            $send,
            [['role' => 'user', 'content' => 'who should I study with?']],
            fn($name, $input) => ['partners' => [['first_name' => 'Maya']], 'asked_for' => $input['limit']]
        );

        $this->assertSame('Study with Maya.', $result['text']);
        $this->assertCount(2, $transport->requests);

        $first = $transport->requests[0];
        $this->assertSame('claude-opus-5-5', $first['model']);
        $this->assertSame(4096, $first['max_tokens']);
        $this->assertSame('low', $first['output_config']['effort']);
        $this->assertSame('You are Rowdy.', $first['system']);
        $this->assertSame('recommend_study_partners', $first['tools'][3]['name']);
        $this->assertSame('object', $first['tools'][3]['input_schema']['type']);

        // The second request carries the assistant turn (thinking signature intact) and the tool result.
        $second = $transport->requests[1]['messages'];
        $this->assertSame('assistant', $second[1]['role']);
        $this->assertSame('thinking', $second[1]['content'][0]['type']);
        $this->assertSame('sigABC', $second[1]['content'][0]['signature']);
        $this->assertSame('tool_use', $second[1]['content'][1]['type']);
        $this->assertSame('user', $second[2]['role']);
        $this->assertSame('tool_result', $second[2]['content'][0]['type']);
        $this->assertSame('toolu_9', $second[2]['content'][0]['tool_use_id']);
        $this->assertStringContainsString('Maya', $second[2]['content'][0]['content']);
        $this->assertSame(2, json_decode($second[2]['content'][0]['content'], true)['asked_for']);
    }

    public function test_the_sdk_marks_tool_errors(): void
    {
        $transport = new FakeTransport([
            $this->apiMessage([['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'add_courses', 'input' => ['courses' => []]]], 'tool_use'),
            $this->apiMessage([['type' => 'text', 'text' => 'Which courses?']], 'end_turn'),
        ]);
        $client = new Anthropic\Client(apiKey: 'test-key', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]);
        $send = fn(array $messages) => $client->messages->create(model: 'claude-opus-5-5', maxTokens: 1024, messages: $messages, tools: ai_tool_definitions());

        $result = ai_run_conversation($send, [['role' => 'user', 'content' => 'add my classes']], function () {
            throw new AiToolError('List at least one course.');
        });

        $this->assertSame('Which courses?', $result['text']);
        $toolResult = $transport->requests[1]['messages'][2]['content'][0];
        $this->assertTrue($toolResult['is_error']);
        $this->assertStringContainsString('List at least one course', $toolResult['content']);
    }

    // ---- configuration ----

    public function test_model_defaults_and_can_be_overridden(): void
    {
        putenv('ANTHROPIC_MODEL');
        $this->assertSame('claude-opus-5-5', ai_model());
        putenv('ANTHROPIC_MODEL=claude-haiku-5-5');
        $this->assertSame('claude-haiku-5-5', ai_model());
        putenv('ANTHROPIC_MODEL');
    }

    public function test_effort_is_limited_to_known_levels(): void
    {
        putenv('AI_EFFORT');
        $this->assertSame('low', ai_effort());
        putenv('AI_EFFORT=high');
        $this->assertSame('high', ai_effort());
        putenv('AI_EFFORT=turbo');
        $this->assertSame('low', ai_effort());
        putenv('AI_EFFORT');
    }

    public function test_limits_have_safe_defaults(): void
    {
        putenv('AI_DAILY_LIMIT_PER_USER');
        putenv('AI_DAILY_LIMIT_GLOBAL');
        $this->assertSame(['user' => 40, 'global' => 400], ai_limits());
        putenv('AI_DAILY_LIMIT_PER_USER=5');
        $this->assertSame(5, ai_limits()['user']);
        putenv('AI_DAILY_LIMIT_PER_USER');
    }
}
