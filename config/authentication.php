<?php

$integerEnvironmentValue = static function (
    string $key,
    int $default
): ?int {
    $value = env($key, $default);

    if (is_string($value) && preg_match('/\A\d+\z/', $value)) {
        return (int) $value;
    }

    return is_int($value) ? $value : null;
};

return [
    'login_max_attempts' => $integerEnvironmentValue(
        'AUTH_LOGIN_MAX_ATTEMPTS',
        5
    ),
    'login_decay_seconds' => $integerEnvironmentValue(
        'AUTH_LOGIN_DECAY_SECONDS',
        60
    ),
    'token_lifetime_minutes' => $integerEnvironmentValue(
        'AUTH_TOKEN_LIFETIME_MINUTES',
        480
    ),
    'token_name' => env('AUTH_TOKEN_NAME', 'doctrack-spa'),
];
