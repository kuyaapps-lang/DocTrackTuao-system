<?php

namespace App\Support;

use Illuminate\Http\Request;

final class PublicLookupSecurity
{
    public const TRACKING_MAX_LENGTH = 50;

    public const QR_MAX_LENGTH = 36;

    public const TRACKING_LIMITER = 'public-document-tracking';

    public const QR_LIMITER = 'public-qr-resolution';

    private const UNAVAILABLE_IP_IDENTITY = "unavailable-client-ip";

    public static function validTrackingNumber(mixed $value): bool
    {
        return is_string($value) &&
            strlen($value) <= self::TRACKING_MAX_LENGTH &&
            preg_match('/\A[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\z/D', $value) === 1;
    }

    public static function validQrToken(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > self::QR_MAX_LENGTH) {
            return false;
        }

        return preg_match(
            '/\A(?:[A-HJ-KM-NP-Z2-9]{5}-[A-HJ-KM-NP-Z2-9]{7}|[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-5][0-9A-Fa-f]{3}-[89ABab][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12})\z/D',
            $value
        ) === 1;
    }

    public static function limiterKey(Request $request, string $category): string
    {
        return self::limiterKeyForIp($request->ip(), $category);
    }

    public static function limiterKeyForIp(mixed $ip, string $category): string
    {
        $identity = self::canonicalIpIdentity($ip);

        return hash('sha256', $category."\0".$identity);
    }

    private static function canonicalIpIdentity(mixed $ip): string
    {
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return self::UNAVAILABLE_IP_IDENTITY;
        }

        $binary = inet_pton($ip);

        if ($binary === false) {
            return self::UNAVAILABLE_IP_IDENTITY;
        }

        return (strlen($binary) === 4 ? 'ipv4:' : 'ipv6:').$binary;
    }

    /**
     * @return array{max_attempts: int, decay_seconds: int}|null
     */
    public static function limiterPolicy(string $category): ?array
    {
        $policy = config('public_access.rate_limits.'.$category);

        if (!is_array($policy)) {
            return null;
        }

        $maxAttempts = self::boundedPositiveInteger(
            $policy['max_attempts'] ?? null,
            1000
        );
        $decaySeconds = self::boundedPositiveInteger(
            $policy['decay_seconds'] ?? null,
            3600
        );

        if ($maxAttempts === null || $decaySeconds === null) {
            return null;
        }

        return [
            'max_attempts' => $maxAttempts,
            'decay_seconds' => $decaySeconds,
        ];
    }

    private static function boundedPositiveInteger(mixed $value, int $maximum): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= $maximum ? $value : null;
        }

        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            return null;
        }

        if ($value === '0' || strlen($value) > strlen((string) $maximum)) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed <= $maximum ? $parsed : null;
    }
}
