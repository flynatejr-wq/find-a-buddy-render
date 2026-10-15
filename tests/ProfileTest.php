<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/profile.php';

class ProfileTest extends TestCase
{
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'major' => 'Computer Science',
            'year' => 'Junior',
            'study_times' => ['Evenings', 'Weekends'],
            'study_style' => 'Small group',
            'study_place' => 'Library',
            'help_text' => 'Programming homework',
        ], $overrides);
    }

    public function test_valid_input_has_no_errors(): void
    {
        [$clean, $errors] = clean_profile_input($this->validInput());
        $this->assertSame([], $errors);
        $this->assertSame('Computer Science', $clean['major']);
        $this->assertSame('Evenings, Weekends', $clean['study_times']);
    }

    public function test_major_is_required(): void
    {
        [, $errors] = clean_profile_input($this->validInput(['major' => '   ']));
        $this->assertArrayHasKey('major', $errors);
    }

    public function test_rejects_unknown_year(): void
    {
        [, $errors] = clean_profile_input($this->validInput(['year' => 'Wizard']));
        $this->assertArrayHasKey('year', $errors);
    }

    public function test_drops_unknown_study_times(): void
    {
        [$clean] = clean_profile_input($this->validInput(['study_times' => ['Mornings', 'Midnight', 'Mornings']]));
        $this->assertSame('Mornings', $clean['study_times']);
    }

    public function test_rejects_unknown_style_and_place(): void
    {
        [, $errors] = clean_profile_input($this->validInput(['study_style' => 'x', 'study_place' => 'y']));
        $this->assertArrayHasKey('study_style', $errors);
        $this->assertArrayHasKey('study_place', $errors);
    }

    public function test_help_text_is_trimmed_and_capped(): void
    {
        [$clean] = clean_profile_input($this->validInput(['help_text' => '  ' . str_repeat('a', 600) . '  ']));
        $this->assertSame(500, mb_strlen($clean['help_text']));
    }

    public function test_missing_fields_do_not_crash(): void
    {
        [, $errors] = clean_profile_input([]);
        $this->assertArrayHasKey('major', $errors);
        $this->assertArrayHasKey('year', $errors);
    }
}
