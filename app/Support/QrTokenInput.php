<?php

namespace App\Support;

final class QrTokenInput
{
    public static function normalize(string $input): string
    {
        $input = trim($input);

        if (PublicLookupSecurity::validQrToken($input)) {
            return $input;
        }

        $parts = parse_url($input);

        if (
            !is_array($parts) ||
            !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) ||
            !isset($parts['host'], $parts['path'])
        ) {
            return $input;
        }

        $path = trim($parts['path'], '/');

        if ($path === '') {
            return $input;
        }

        return rawurldecode(basename($path));
    }

    public static function validToken(string $input): ?string
    {
        $token = self::normalize($input);

        return PublicLookupSecurity::validQrToken($token) ? $token : null;
    }
}
