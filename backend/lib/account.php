<?php

/** Returns a trimmed name (1-100 characters), or null if it is empty, too long or not a string. */
function clean_person_name($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > 100) {
        return null;
    }
    return $value;
}

/** Returns an error message for an unacceptable new password, or null if it is fine. */
function validate_new_password(string $new, string $current): ?string
{
    if (strlen($new) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if ($new === $current) {
        return 'Choose a different password than your current one.';
    }
    return null;
}
