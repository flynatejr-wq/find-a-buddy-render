<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/lib/availability.php';

class AvailabilityTest extends TestCase
{
    public function test_encode_availability_keeps_only_valid_slots(): void
    {
        $json = encode_availability(['Mon-Morning', 'Tue-Evening', 'Bogus-Slot', 'Fri-Afternoon']);
        $this->assertSame(['Mon-Morning', 'Tue-Evening', 'Fri-Afternoon'], json_decode($json, true));
    }

    public function test_encode_availability_dedupes(): void
    {
        $json = encode_availability(['Mon-Morning', 'Mon-Morning']);
        $this->assertSame(['Mon-Morning'], json_decode($json, true));
    }

    public function test_decode_availability_handles_empty_and_null(): void
    {
        $this->assertSame([], decode_availability(null));
        $this->assertSame([], decode_availability(''));
    }

    public function test_decode_availability_parses_json(): void
    {
        $this->assertSame(['Mon-Morning'], decode_availability('["Mon-Morning"]'));
    }

    public function test_overlapping_availability_returns_shared_slots_only(): void
    {
        $a = encode_availability(['Mon-Morning', 'Tue-Evening']);
        $b = encode_availability(['Tue-Evening', 'Wed-Afternoon']);
        $this->assertSame(['Tue-Evening'], overlapping_availability($a, $b));
    }
}
