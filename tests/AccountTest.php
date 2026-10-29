<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/account.php';

class AccountTest extends TestCase
{
    public function test_name_is_trimmed(): void
    {
        $this->assertSame('Jordan', clean_person_name('  Jordan '));
    }

    public function test_empty_or_non_string_name_is_rejected(): void
    {
        $this->assertNull(clean_person_name('   '));
        $this->assertNull(clean_person_name(null));
        $this->assertNull(clean_person_name(['x']));
    }

    public function test_name_over_100_characters_is_rejected(): void
    {
        $this->assertNull(clean_person_name(str_repeat('a', 101)));
        $this->assertSame(100, mb_strlen(clean_person_name(str_repeat('a', 100))));
    }

    public function test_short_password_is_rejected(): void
    {
        $this->assertSame('Password must be at least 8 characters.', validate_new_password('short', 'old-password'));
    }

    public function test_same_password_is_rejected(): void
    {
        $this->assertNotNull(validate_new_password('samepassword', 'samepassword'));
    }

    public function test_good_new_password_passes(): void
    {
        $this->assertNull(validate_new_password('brand-new-pass', 'old-password'));
    }
}
