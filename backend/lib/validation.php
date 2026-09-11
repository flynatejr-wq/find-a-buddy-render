<?php

function is_valid_ssu_email(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return (bool) preg_match('/@savannahstate\.edu$/i', $email);
}

function validate_registration_input(array $data): array
{
    $errors = [];

    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $firstName = trim($data['first_name'] ?? '');
    $lastName = trim($data['last_name'] ?? '');

    if ($email === '' || !is_valid_ssu_email($email)) {
        $errors['email'] = 'Email must be a valid @savannahstate.edu address.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }
    if ($firstName === '') {
        $errors['first_name'] = 'First name is required.';
    }
    if ($lastName === '') {
        $errors['last_name'] = 'Last name is required.';
    }

    return $errors;
}
