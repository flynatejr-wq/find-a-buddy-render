<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/reset.php';

class ResetTest extends TestCase
{
    public function test_token_is_64_hex_characters_and_hash_matches(): void
    {
        [$token, $hash] = new_reset_token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(hash_reset_token($token), $hash);
        $this->assertNotSame($token, $hash);
    }

    public function test_tokens_are_unique(): void
    {
        $this->assertNotSame(new_reset_token()[0], new_reset_token()[0]);
    }

    public function test_fresh_unused_row_is_usable(): void
    {
        $this->assertTrue(reset_is_usable(['used' => 0, 'expires_at' => 2000], 1000));
    }

    public function test_expired_row_is_not_usable(): void
    {
        $this->assertFalse(reset_is_usable(['used' => 0, 'expires_at' => 1000], 1000));
        $this->assertFalse(reset_is_usable(['used' => 0, 'expires_at' => 500], 1000));
    }

    public function test_used_row_is_not_usable(): void
    {
        $this->assertFalse(reset_is_usable(['used' => 1, 'expires_at' => 9999], 1000));
    }

    public function test_reset_link_is_built_from_base_url(): void
    {
        $this->assertSame(
            'https://example.com/frontend/index.html?reset=abc123',
            reset_link('https://example.com/', 'abc123')
        );
    }
}
