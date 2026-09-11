<?php

const AVAILABILITY_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
const AVAILABILITY_BLOCKS = ['Morning', 'Afternoon', 'Evening'];

function encode_availability(array $slots): string
{
    $valid = [];
    foreach ($slots as $slot) {
        [$day, $block] = array_pad(explode('-', (string) $slot, 2), 2, null);
        if (in_array($day, AVAILABILITY_DAYS, true) && in_array($block, AVAILABILITY_BLOCKS, true)) {
            $valid[] = "$day-$block";
        }
    }
    return json_encode(array_values(array_unique($valid)));
}

function decode_availability(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function overlapping_availability(string $jsonA, string $jsonB): array
{
    $a = decode_availability($jsonA);
    $b = decode_availability($jsonB);
    return array_values(array_intersect($a, $b));
}
