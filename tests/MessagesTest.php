<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/messages.php';

class MessagesTest extends TestCase
{
    public function test_body_is_trimmed(): void
    {
        $this->assertSame('hi there', clean_message_body("  hi there \n"));
    }

    public function test_empty_or_whitespace_body_is_rejected(): void
    {
        $this->assertNull(clean_message_body('   '));
        $this->assertNull(clean_message_body(''));
        $this->assertNull(clean_message_body(null));
    }

    public function test_non_string_body_is_rejected(): void
    {
        $this->assertNull(clean_message_body(['a']));
        $this->assertNull(clean_message_body(123));
    }

    public function test_long_body_is_capped_at_1000_characters(): void
    {
        $this->assertSame(1000, mb_strlen(clean_message_body(str_repeat('a', 1500))));
    }

    public function test_multibyte_body_is_capped_by_characters_not_bytes(): void
    {
        $this->assertSame(1000, mb_strlen(clean_message_body(str_repeat('é', 1200))));
    }

    public function test_valid_request_actions(): void
    {
        $this->assertSame('accepted', request_action_to_status('accept'));
        $this->assertSame('declined', request_action_to_status('decline'));
    }

    public function test_invalid_request_action_returns_null(): void
    {
        $this->assertNull(request_action_to_status('delete'));
        $this->assertNull(request_action_to_status(''));
        $this->assertNull(request_action_to_status(null));
    }
}
