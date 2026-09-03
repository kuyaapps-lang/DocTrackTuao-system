<?php

namespace App\Support;

final class SecurityPolicy
{
    public static function trustedHosts(): ?array
    {
        $configured = config('security.trusted_hosts');

        if (!is_string($configured) || $configured === '') {
            return null;
        }

        $hosts = explode(',', $configured);
        $normalized = [];

        foreach ($hosts as $host) {
            if ($host === '' || $host !== trim($host) || !self::validHost($host)) {
                return null;
            }

            $normalized[] = self::normalizeHost($host);
        }

        return array_values(array_unique($normalized));
    }

    public static function hostIsTrusted(mixed $host): bool
    {
        $trusted = self::trustedHosts();

        if ($trusted === null || !is_string($host)) {
            return false;
        }

        $normalized = self::normalizeRequestHost($host);

        return $normalized !== null && in_array($normalized, $trusted, true);
    }

    public static function contentSecurityPolicy(bool $local): string
    {
        $script = ["'self'"];
        $style = ["'self'", "'unsafe-inline'"];
        $image = ["'self'", 'data:', 'blob:'];
        $connect = ["'self'"];

        if ($local && ($origin = self::viteOrigin()) !== null) {
            $script[] = $origin;
            $style[] = $origin;
            $image[] = $origin;
            $connect[] = $origin;
            $connect[] = preg_replace('/^http/', 'ws', $origin);
        }

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            'img-src '.implode(' ', $image),
            "font-src 'self'",
            'connect-src '.implode(' ', $connect),
            "worker-src 'self'",
            "media-src 'none'",
            "frame-src 'none'",
            "manifest-src 'self'",
        ]).';';
    }

    public static function hstsEnabled(): bool
    {
        return config('security.hsts_enabled') === true;
    }

    private static function validHost(string $host): bool
    {
        if (
            preg_match('/[\x00-\x20\x7f]/', $host) === 1 ||
            strpbrk($host, '/*?#@') !== false ||
            str_contains($host, '://')
        ) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (preg_match('/\A[0-9.]+\z/D', $host) === 1) {
            return false;
        }

        return strlen($host) <= 253 && preg_match(
            '/\A(?=.{1,253}\z)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*\z/D',
            $host
        ) === 1;
    }

    private static function normalizeHost(string $host): string
    {
        $binary = @inet_pton($host);

        return $binary === false ? strtolower($host) : inet_ntop($binary);
    }

    private static function normalizeRequestHost(string $host): ?string
    {
        if (str_starts_with($host, '[')) {
            if (preg_match('/\A\[([^]]+)](?::([^:]*))?\z/D', $host, $match) !== 1) {
                return null;
            }

            if (array_key_exists(2, $match) && !self::validPort($match[2])) {
                return null;
            }

            return self::validHost($match[1]) ? self::normalizeHost($match[1]) : null;
        }

        if (substr_count($host, ':') === 1) {
            [$candidate, $port] = explode(':', $host, 2);
            if (!self::validPort($port)) {
                return null;
            }
            $host = $candidate;
        }

        return self::validHost($host) ? self::normalizeHost($host) : null;
    }

    private static function validPort(string $port): bool
    {
        return preg_match('/\A[0-9]+\z/D', $port) === 1 &&
            strlen($port) <= 5 &&
            (int) $port >= 1 &&
            (int) $port <= 65535;
    }

    private static function validCanonicalPort(string $port): bool
    {
        return preg_match('/\A[1-9][0-9]{0,4}\z/D', $port) === 1 &&
            (int) $port <= 65535;
    }

    private static function viteOrigin(): ?string
    {
        $url = config('security.vite_dev_server_url');

        if ((!is_string($url) || $url === '') && is_file(public_path('hot'))) {
            $hotUrl = trim((string) file_get_contents(public_path('hot')));
            $url = $hotUrl;
        }

        if (!is_string($url) || $url === '') {
            return null;
        }

        if (preg_match(
            '/\A(https?):\/\/(localhost|127\.0\.0\.1|\[::1])(?::([0-9]+))?\z/iD',
            $url,
            $match
        ) !== 1 || (isset($match[3]) && !self::validCanonicalPort($match[3]))) {
            return null;
        }

        $port = isset($match[3]) ? ':'.$match[3] : '';

        return strtolower($match[1]).'://'.strtolower($match[2]).$port;
    }
}
