<?php

namespace App\Http\Middleware;

use App\Support\SecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::applyTo($next($request), $request);
    }

    public static function applyTo(Response $response, Request $request): Response
    {
        if ($request->is('api', 'api/*') && $response->getStatusCode() >= 500) {
            $approved = self::approvedApplicationFailure($response, $request);
            $response = $approved === null
                ? response()->json(['message' => 'An unexpected error occurred.'], 500)
                : response()->json($approved['body'], $approved['status']);
        }

        $response->headers->set(
            'Content-Security-Policy',
            SecurityPolicy::contentSecurityPolicy(app()->environment('local'))
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );
        $response->headers->remove('X-Powered-By');

        if (
            ($request->is('api/*') || self::contentTypeRequiresNoStore($response)) &&
            !$request->is('build/*')
        ) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if (SecurityPolicy::hstsEnabled() && $request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        } else {
            $response->headers->remove('Strict-Transport-Security');
        }

        return $response;
    }

    private static function contentTypeRequiresNoStore(Response $response): bool
    {
        $values = $response->headers->all('Content-Type');

        if ($values === []) {
            return false;
        }

        if (count($values) !== 1 || !is_string($values[0])) {
            return true;
        }

        $contentType = trim($values[0], " \t");
        if (
            $contentType === '' ||
            preg_match('/[\x00-\x08\x0A-\x1F\x7F,]/', $contentType) === 1
        ) {
            return true;
        }

        $parts = explode(';', $contentType);
        $mediaType = strtolower(trim(array_shift($parts), " \t"));

        if ($mediaType === 'text/html') {
            return true;
        }

        $token = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";
        if (preg_match("/\\A{$token}\\/{$token}\\z/D", $mediaType) !== 1) {
            return true;
        }

        foreach ($parts as $parameter) {
            if (preg_match(
                "/\\A[ \\t]*{$token}[ \\t]*=[ \\t]*(?:{$token}|\"[^\"\\r\\n]*\")[ \\t]*\\z/D",
                $parameter
            ) !== 1) {
                return true;
            }
        }

        return false;
    }

    private static function approvedApplicationFailure(Response $response, Request $request): ?array
    {
        $headers = $response->headers->all();
        if ($response->headers->getCookies() !== [] || !self::approvedTransportHeaders($headers)) {
            return null;
        }

        $body = json_decode($response->getContent(), true);
        if (!is_array($body) || array_keys($body) !== ['message'] || !is_string($body['message'])) {
            return null;
        }

        if ($response->getStatusCode() === 503 && $body === [
            'message' => 'Public lookup is temporarily unavailable.',
        ]) {
            $middleware = $request->route()?->gatherMiddleware() ?? [];

            return in_array('throttle:public-document-tracking', $middleware, true) ||
                in_array('throttle:public-qr-resolution', $middleware, true)
                    ? ['status' => 503, 'body' => $body]
                    : null;
        }

        if ($response->getStatusCode() !== 500) {
            return null;
        }

        $approved = [
            \App\Http\Controllers\AuthController::class.'@login' =>
                'Authentication is temporarily unavailable.',
            \App\Http\Controllers\DashboardController::class.'@summary' =>
                'Dashboard summary is temporarily unavailable.',
            \App\Http\Controllers\DocumentAttachmentController::class.'@store' =>
                'Attachment could not be stored.',
            \App\Http\Controllers\DocumentAttachmentController::class.'@destroy' =>
                'Attachment could not be deleted.',
        ];
        $action = $request->route()?->getActionName();

        return isset($approved[$action]) && $body === ['message' => $approved[$action]]
            ? ['status' => 500, 'body' => $body]
            : null;
    }

    private static function approvedTransportHeaders(array $headers): bool
    {
        if (
            array_diff(
                array_keys($headers),
                ['content-type', 'cache-control', 'date', 'access-control-allow-origin']
            ) !== [] ||
            ($headers['content-type'] ?? null) !== ['application/json'] ||
            ($headers['cache-control'] ?? null) !== ['no-cache, private'] ||
            !self::validHttpDateValues($headers['date'] ?? null)
        ) {
            return false;
        }

        return !isset($headers['access-control-allow-origin']) ||
            $headers['access-control-allow-origin'] === ['*'];
    }

    private static function validHttpDateValues(mixed $values): bool
    {
        if (!is_array($values) || count($values) !== 1 || !is_string($values[0])) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!D, d M Y H:i:s \G\M\T',
            $values[0],
            new \DateTimeZone('UTC')
        );

        return $date !== false && $date->format('D, d M Y H:i:s \G\M\T') === $values[0];
    }
}
