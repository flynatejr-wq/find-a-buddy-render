<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/validation.php';

class ValidationTest extends TestCase
{
    public function test_accepts_valid_ssu_email(): void
    {
        $this->assertTrue(is_valid_ssu_email('jdoe@student.savannahstate.edu'));
    }

    public function test_rejects_non_ssu_email(): void
    {
        $this->assertFalse(is_valid_ssu_email('jdoe@gmail.com'));
    }

    public function test_rejects_bare_savannahstate_domain(): void
    {
        $this->assertFalse(is_valid_ssu_email('jdoe@savannahstate.edu'));
    }

    public function test_rejects_malformed_email(): void
    {
        $this->assertFalse(is_valid_ssu_email('not-an-email'));
    }

    public function test_validate_registration_input_flags_all_missing_fields(): void
    {
        $errors = validate_registration_input([]);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('password', $errors);
        $this->assertArrayHasKey('first_name', $errors);
        $this->assertArrayHasKey('last_name', $errors);
    }

    public function test_validate_registration_input_rejects_short_password(): void
    {
        $errors = validate_registration_input([
            'email' => 'jdoe@student.savannahstate.edu',
            'password' => 'short',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $this->assertArrayHasKey('password', $errors);
    }

    public function test_validate_registration_input_passes_for_good_data(): void
    {
        $errors = validate_registration_input([
            'email' => 'jdoe@student.savannahstate.edu',
            'password' => 'supersecret1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $this->assertSame([], $errors);
    }
}
