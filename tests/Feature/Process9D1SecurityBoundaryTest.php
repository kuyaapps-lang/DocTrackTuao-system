<?php

namespace Tests\Feature;

use App\Models\User;
use App\Http\Controllers\AuthController;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustConfiguredHosts;
use App\Support\SecurityPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Process9D1SecurityBoundaryTest extends TestCase
{
    private const PRODUCTION_CSP = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src 'self'; media-src 'none'; frame-src 'none'; manifest-src 'self';";

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'security.trusted_hosts' => 'localhost,127.0.0.1,::1,portal.test,192.0.2.10,2001:db8::10',
            'security.hsts_enabled' => false,
            'security.vite_dev_server_url' => null,
        ]);

        $this->createSnapshotSchema();
        $this->registerIsolatedRoutes();
    }

    protected function tearDown(): void
    {
        foreach ([
            'document_attachments', 'document_qr_codes',
            'document_processing_logs', 'document_routes', 'documents',
            'audit_logs', 'personal_access_tokens',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    #[DataProvider('responseCases')]
    public function test_security_headers_preserve_api_status_and_content_type(
        string $path,
        int $status,
        string $contentType
    ): void {
        $response = $this->snapshotRequest(fn () => $this->getJson($path));

        $response->assertStatus($status);
        $this->assertStringStartsWith($contentType, $response->headers->get('Content-Type'));
        $this->assertSecurityHeaders($response);
    }

    public static function responseCases(): iterable
    {
        yield 'public success' => ['/api/security-test/public', 200, 'application/json'];
        yield 'unauthenticated' => ['/api/security-test/protected', 401, 'application/json'];
        yield 'forbidden' => ['/api/security-test/forbidden', 403, 'application/json'];
        yield 'not found' => ['/api/security-test/not-found', 404, 'application/json'];
        yield 'conflict' => ['/api/security-test/conflict', 409, 'application/json'];
        yield 'safe response exception' => ['/api/security-test/safe-response-exception', 410, 'application/json'];
        yield 'validation' => ['/api/security-test/validation', 422, 'application/json'];
        yield 'throttled' => ['/api/security-test/throttled', 429, 'application/json'];
    }

    public function test_protected_success_spa_html_and_redirect_keep_their_contracts(): void
    {
        Sanctum::actingAs(new User(['name' => 'Synthetic User']));

        $protected = $this->snapshotRequest(
            fn () => $this->getJson('/api/security-test/protected')
        )->assertOk()->assertExactJson(['message' => 'protected']);
        $this->assertSecurityHeaders($protected);

        $html = $this->snapshotRequest(fn () => $this->get('/'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertSecurityHeaders($html);

        $redirect = $this->snapshotRequest(fn () => $this->get('/security-test-redirect'))
            ->assertRedirect('/');
        $this->assertSecurityHeaders($redirect);
    }

    #[DataProvider('htmlContentTypeCases')]
    public function test_html_content_type_variants_are_not_cacheable(string $contentType): void
    {
        $response = $this->applyResponse(
            response('<p>HTML</p>', 200, ['Content-Type' => $contentType]),
            '/security-test-html'
        );

        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSecurityHeaders($response);
    }

    public static function htmlContentTypeCases(): iterable
    {
        yield 'media type only' => ['text/html'];
        yield 'live lowercase charset' => ['text/html; charset=utf-8'];
        yield 'framework uppercase charset' => ['text/html; charset=UTF-8'];
        yield 'case and optional whitespace' => ["  TeXt/HtMl \t; ChArSeT = uTf-8  "];
    }

    public function test_api_json_keeps_no_store_while_non_html_and_downloads_are_not_reclassified(): void
    {
        $api = $this->applyResponse(response()->json(['message' => 'safe']), '/api/security-test/cache');
        $api->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSecurityHeaders($api);

        $plain = $this->applyResponse(
            response('plain', 200, ['Content-Type' => 'text/plain; charset=UTF-8']),
            '/security-test-plain'
        );
        $plain->assertHeader('Cache-Control', 'no-cache, private');
        $this->assertSecurityHeaders($plain);

        $download = $this->applyResponse(
            response()->streamDownload(
                static function (): void {},
                'safe.pdf',
                ['Content-Type' => 'application/pdf']
            ),
            '/security-test-download'
        );
        $download->assertHeader('Content-Disposition', 'attachment; filename=safe.pdf');
        $this->assertNotSame('no-store, private', $download->headers->get('Cache-Control'));
        $this->assertSecurityHeaders($download);
    }

    #[DataProvider('ambiguousContentTypeCases')]
    public function test_malformed_or_multi_valued_content_types_fail_closed(string $case): void
    {
        $response = response('ambiguous');

        if ($case === 'multiple-values') {
            $response->headers->set('Content-Type', ['text/html', 'application/json']);
        } else {
            $response->headers->set('Content-Type', 'application/pdf, text/html');
        }

        $result = $this->applyResponse($response, '/security-test-ambiguous');
        $result->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSecurityHeaders($result);
    }

    public static function ambiguousContentTypeCases(): iterable
    {
        yield 'multiple values' => ['multiple-values'];
        yield 'comma ambiguous value' => ['comma-value'];
    }

    #[DataProvider('debugValues')]
    public function test_unexpected_api_failures_are_generic_regardless_of_debug_or_accept(
        bool $debug,
        string $accept
    ): void {
        config(['app.debug' => $debug]);

        $response = $this->snapshotRequest(
            fn () => $this->withHeader('Accept', $accept)
                ->get('/api/security-test/failure')
        )->assertStatus(500)->assertExactJson([
            'message' => 'An unexpected error occurred.',
        ]);
        $this->assertSecurityHeaders($response);

        $serialized = strtolower($response->getContent().json_encode($response->headers->all()));
        foreach (['synthetic secret failure', 'runtimeexception', 'trace', '.php', 'sql', 'app_key'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_explicit_abort_500_is_replaced_with_generic_json(): void
    {
        config(['app.debug' => true]);

        $this->assertGenericApiFailure(
            fn () => $this->withHeader('Accept', 'text/html')
                ->get('/api/security-test/failure-abort'),
            ['sensitive explicit abort detail']
        );
    }

    #[DataProvider('responseExceptionFailureCases')]
    public function test_response_exceptions_at_500_or_above_are_replaced_with_generic_json(
        string $path,
        ?string $accept,
        array $sensitiveMarkers
    ): void {
        config(['app.debug' => false]);

        $this->assertGenericApiFailure(function () use ($path, $accept) {
            $request = $accept === null ? $this : $this->withHeader('Accept', $accept);

            return $request->get($path);
        }, $sensitiveMarkers);
    }

    public static function responseExceptionFailureCases(): iterable
    {
        yield 'sensitive JSON 500 without Accept' => [
            '/api/security-test/failure-response-json',
            null,
            ['sensitive response json', 'x-sensitive-diagnostic'],
        ];
        yield 'sensitive HTML 500 with unusual Accept' => [
            '/api/security-test/failure-response-html',
            'application/xml',
            ['sensitive response html', 'x-sensitive-diagnostic'],
        ];
        yield 'sensitive response 503' => [
            '/api/security-test/failure-response-503',
            'application/json',
            ['sensitive response 503', 'x-sensitive-diagnostic'],
        ];
    }

    public function test_exact_approved_controller_failure_is_rebuilt_safely(): void
    {
        $response = $this->applyResponseForRoute(
            [AuthController::class, 'login'],
            response()->json(
                ['message' => 'Authentication is temporarily unavailable.'],
                500,
                ['Access-Control-Allow-Origin' => '*']
            )
        );

        $response->assertStatus(500)->assertExactJson([
            'message' => 'Authentication is temporarily unavailable.',
        ]);
        $this->assertSecurityHeaders($response);
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    #[DataProvider('unapprovedControllerFailureCases')]
    public function test_controller_failure_near_matches_are_generic(
        array $action,
        string $case,
        string $marker
    ): void {
        $message = 'Authentication is temporarily unavailable.';
        $response = match ($case) {
            'exact' => response()->json(['message' => $message], 500),
            'wrong-body' => response()->json(['message' => 'Hostile wrong body'], 500),
            'wrong-content-type' => response($message, 500, ['Content-Type' => 'text/plain']),
            'extra-field' => response()->json(['message' => $message, 'detail' => 'Hostile extra field'], 500),
            'unsafe-header' => response()->json(['message' => $message], 500, ['X-Internal-Diagnostic' => 'Hostile header']),
        };
        $result = $this->applyResponseForRoute($action, $response);

        $this->assertGenericAppliedResponse($result, [$marker]);
    }

    public static function unapprovedControllerFailureCases(): iterable
    {
        yield 'wrong action' => [
            [AuthController::class, 'logout'], 'exact', 'authentication is temporarily unavailable',
        ];
        yield 'wrong body' => [
            [AuthController::class, 'login'], 'wrong-body', 'hostile wrong body',
        ];
        yield 'wrong content type' => [
            [AuthController::class, 'login'], 'wrong-content-type', 'authentication is temporarily unavailable',
        ];
        yield 'extra field' => [
            [AuthController::class, 'login'], 'extra-field', 'hostile extra field',
        ];
        yield 'unsafe header' => [
            [AuthController::class, 'login'], 'unsafe-header', 'hostile header',
        ];
    }

    public function test_exact_public_lookup_unavailable_is_rebuilt_safely(): void
    {
        $response = $this->applyResponseForRoute(
            fn () => null,
            response()->json(
                ['message' => 'Public lookup is temporarily unavailable.'],
                503,
                ['Access-Control-Allow-Origin' => '*']
            ),
            ['throttle:public-document-tracking']
        );

        $response->assertStatus(503)->assertExactJson([
            'message' => 'Public lookup is temporarily unavailable.',
        ]);
        $this->assertSecurityHeaders($response);
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    #[DataProvider('unapprovedPublicLookupFailureCases')]
    public function test_public_lookup_unavailable_near_matches_are_generic(
        array $middleware,
        string $case,
        string $marker
    ): void {
        $body = ['message' => 'Public lookup is temporarily unavailable.'];
        $response = match ($case) {
            'exact' => response()->json($body, 503),
            'altered-body' => response()->json(['message' => 'Hostile altered 503'], 503),
            'extra-field' => response()->json($body + ['detail' => 'Hostile extra 503'], 503),
            'wrong-content-type' => response($body['message'], 503, ['Content-Type' => 'text/plain']),
            'unsafe-header' => response()->json($body, 503, ['Set-Cookie' => 'unsafe-marker=value']),
        };
        $result = $this->applyResponseForRoute(fn () => null, $response, $middleware);

        $this->assertGenericAppliedResponse($result, [$marker]);
    }

    public static function unapprovedPublicLookupFailureCases(): iterable
    {
        yield 'wrong middleware' => [[], 'exact', 'public lookup is temporarily unavailable'];
        yield 'altered body' => [['throttle:public-qr-resolution'], 'altered-body', 'hostile altered 503'];
        yield 'extra body field' => [['throttle:public-qr-resolution'], 'extra-field', 'hostile extra 503'];
        yield 'wrong content type' => [['throttle:public-qr-resolution'], 'wrong-content-type', 'public lookup is temporarily unavailable'];
        yield 'unsafe header' => [['throttle:public-qr-resolution'], 'unsafe-header', 'unsafe-marker'];
    }

    #[DataProvider('unapprovedTransportHeaderCases')]
    public function test_approved_response_rejects_unsafe_singleton_or_multiple_header_values(
        string $case,
        string $marker
    ): void {
        $response = response()->json([
            'message' => 'Authentication is temporarily unavailable.',
        ], 500);

        match ($case) {
            'duplicate-content-type' => $response->headers->set('Content-Type', 'text/hostile-marker', false),
            'multiple-cors' => $response->headers->set('Access-Control-Allow-Origin', ['*', 'https://hostile-marker.test']),
            'multiple-cache-control' => $response->headers->set('Cache-Control', ['no-cache, private', 'public, hostile-marker']),
            'unsafe-cache-control' => $response->headers->set('Cache-Control', 'public, hostile-marker'),
            'duplicate-date' => $response->headers->set('Date', 'Sun, 03 Sep 2000 13:27:30 GMT', false),
            'malformed-date' => $response->headers->set('Date', 'hostile-marker-date'),
            'control-date' => $response->headers->set('Date', "Wed, 02 Sep 2026 13:27:30 GMT\r\nX-Injected: hostile-marker"),
        };

        $result = $this->applyResponseForRoute([AuthController::class, 'login'], $response);
        $this->assertGenericAppliedResponse($result, [$marker]);
    }

    public static function unapprovedTransportHeaderCases(): iterable
    {
        yield 'duplicate content type' => ['duplicate-content-type', 'hostile-marker'];
        yield 'multiple CORS values' => ['multiple-cors', 'hostile-marker'];
        yield 'multiple cache control values' => ['multiple-cache-control', 'hostile-marker'];
        yield 'unsafe cache control' => ['unsafe-cache-control', 'hostile-marker'];
        yield 'duplicate date' => ['duplicate-date', 'Sun, 03 Sep 2000'];
        yield 'malformed date' => ['malformed-date', 'hostile-marker'];
        yield 'control-bearing date' => ['control-date', 'hostile-marker'];
    }

    public static function debugValues(): iterable
    {
        yield 'debug false json' => [false, 'application/json'];
        yield 'debug true html accept' => [true, 'text/html'];
        yield 'debug true wildcard accept' => [true, '*/*'];
    }

    public function test_html_failures_are_not_converted_to_api_json(): void
    {
        config(['app.debug' => false]);

        $response = $this->snapshotRequest(fn () => $this->get('/security-test-failure'))
            ->assertStatus(500);

        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        $this->assertSecurityHeaders($response);
    }

    public function test_csp_is_exact_and_development_additions_are_loopback_only(): void
    {
        $this->assertSame(self::PRODUCTION_CSP, SecurityPolicy::contentSecurityPolicy(false));
        $this->assertStringNotContainsString('*', self::PRODUCTION_CSP);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", self::PRODUCTION_CSP);
        $this->assertStringNotContainsString("'unsafe-eval'", self::PRODUCTION_CSP);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", self::PRODUCTION_CSP);
        $this->assertStringContainsString("img-src 'self' data: blob:", self::PRODUCTION_CSP);

        config(['security.vite_dev_server_url' => 'http://127.0.0.1:5173']);
        $development = SecurityPolicy::contentSecurityPolicy(true);
        $this->assertStringContainsString('http://127.0.0.1:5173', $development);
        $this->assertStringContainsString('ws://127.0.0.1:5173', $development);
        $this->assertStringContainsString("img-src 'self' data: blob: http://127.0.0.1:5173", $development);
        $this->assertStringNotContainsString("img-src 'self' data: blob: ws://127.0.0.1:5173", $development);
        $this->assertStringNotContainsString('127.0.0.1:5173', self::PRODUCTION_CSP);

        foreach (['https://example.test:5173', 'http://localhost:5173/path', 'http://user@localhost:5173'] as $unsafe) {
            config(['security.vite_dev_server_url' => $unsafe]);
            $this->assertSame(self::PRODUCTION_CSP, SecurityPolicy::contentSecurityPolicy(true));
        }
    }

    #[DataProvider('vitePortCases')]
    public function test_vite_ports_preserve_raw_canonical_form_across_supported_sources(
        string $source,
        string $url,
        bool $allowed
    ): void
    {
        $previousConfig = config('security.vite_dev_server_url');
        $hotPath = public_path('hot');
        $hotExisted = is_file($hotPath);
        $previousHot = $hotExisted ? file_get_contents($hotPath) : null;

        try {
            if ($source === 'config') {
                config(['security.vite_dev_server_url' => $url]);
            } else {
                config(['security.vite_dev_server_url' => null]);
                file_put_contents($hotPath, $url);
            }

            $policy = SecurityPolicy::contentSecurityPolicy(true);
            $webSocketUrl = preg_replace('/^http/', 'ws', $url);

            if ($allowed) {
                $this->assertStringContainsString('script-src \'self\' '.$url, $policy);
                $this->assertStringContainsString('style-src \'self\' \'unsafe-inline\' '.$url, $policy);
                $this->assertStringContainsString("img-src 'self' data: blob: ".$url, $policy);
                $this->assertStringNotContainsString("img-src 'self' data: blob: ".$webSocketUrl, $policy);
                $this->assertStringContainsString('connect-src \'self\' '.$url.' '.$webSocketUrl, $policy);
            } else {
                $this->assertSame(self::PRODUCTION_CSP, $policy);
                $this->assertStringNotContainsString($url, $policy);
                $this->assertStringNotContainsString($webSocketUrl, $policy);
            }

            $this->assertStringNotContainsString('localhost:', self::PRODUCTION_CSP);
            $this->assertStringNotContainsString('127.0.0.1:', self::PRODUCTION_CSP);
        } finally {
            config(['security.vite_dev_server_url' => $previousConfig]);

            if ($hotExisted) {
                file_put_contents($hotPath, $previousHot);
            } elseif (is_file($hotPath)) {
                unlink($hotPath);
            }
        }
    }

    public static function vitePortCases(): iterable
    {
        $cases = [
            'minimum port' => ['http://localhost:1', true],
            'common port' => ['http://127.0.0.1:5173', true],
            'maximum port' => ['https://[::1]:65535', true],
            'zero' => ['http://localhost:0', false],
            'double zero' => ['http://localhost:00', false],
            'leading zero' => ['http://localhost:01', false],
            'padded minimum' => ['http://localhost:00001', false],
            'padded common port' => ['http://localhost:05173', false],
            'above maximum' => ['http://localhost:65536', false],
            'excessive length' => ['http://localhost:999999999999', false],
        ];

        foreach (['config', 'hot'] as $source) {
            foreach ($cases as $name => [$url, $allowed]) {
                yield $source.' '.$name => [$source, $url, $allowed];
            }
        }

        foreach ([
            'http://localhost:+80', 'http://localhost:-80', 'http://localhost:80.0',
            'http://localhost:8e1', 'http://localhost:0x50', 'http://localhost: 80',
            'http://localhost:', 'http://localhost:80:90',
        ] as $url) {
            yield $url => ['config', $url, false];
        }
    }

    #[DataProvider('validHostCases')]
    public function test_configured_hosts_are_exact_and_ports_are_ignored_safely(string $host): void
    {
        $this->snapshotRequest(fn () => $this->getWithHost($host))->assertOk();
    }

    public static function validHostCases(): iterable
    {
        yield 'localhost' => ['localhost'];
        yield 'loopback' => ['127.0.0.1'];
        yield 'hostname' => ['portal.test'];
        yield 'hostname with minimum port' => ['portal.test:1'];
        yield 'hostname with maximum port' => ['portal.test:65535'];
        yield 'ipv4 with port' => ['192.0.2.10:8080'];
        yield 'ipv6 with port' => ['[2001:db8::10]:8080'];
    }

    #[DataProvider('invalidHostConfigurationCases')]
    public function test_invalid_host_configuration_fails_closed(string $configured): void
    {
        config(['security.trusted_hosts' => $configured]);
        $this->assertNull(SecurityPolicy::trustedHosts());

        $response = $this->snapshotRequest(fn () => $this->getWithHost('localhost'))
            ->assertStatus(400)
            ->assertExactJson(['message' => 'Invalid request host.']);
        if ($configured !== '') {
            $this->assertStringNotContainsString($configured, $response->getContent());
        }
    }

    public static function invalidHostConfigurationCases(): iterable
    {
        foreach (['', '*', ' localhost', 'localhost,', 'https://localhost', 'localhost/path', 'localhost?x', 'localhost#x', 'user@localhost', '999.1.1.1'] as $value) {
            yield $value => [$value];
        }
        yield 'control character' => ["localhost\n"];
    }

    #[DataProvider('hostileHostCases')]
    public function test_untrusted_hosts_are_not_reflected(string $host): void
    {
        $response = $this->snapshotRequest(fn () => $this->getWithHost($host))
            ->assertStatus(400)
            ->assertExactJson(['message' => 'Invalid request host.']);
        $this->assertStringNotContainsString($host, $response->getContent());
    }

    public static function hostileHostCases(): iterable
    {
        yield 'untrusted' => ['evil.test'];
        yield 'suffix spoof' => ['portal.test.evil.test'];
        yield 'prefix spoof' => ['evilportal.test'];
    }

    #[DataProvider('invalidRequestHostPortCases')]
    public function test_request_host_ports_are_strictly_parsed_and_bounded(string $host): void
    {
        $this->assertFalse(SecurityPolicy::hostIsTrusted($host));

        $request = Request::create('/api/security-test/public');
        $request->server->set('HTTP_HOST', $host);
        $response = (new TrustConfiguredHosts())->handle(
            $request,
            fn () => response()->json(['message' => 'bypassed'])
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['message' => 'Invalid request host.'], $response->getData(true));
        $this->assertStringNotContainsString($host, $response->getContent());
    }

    public static function invalidRequestHostPortCases(): iterable
    {
        foreach ([
            'portal.test:0', 'portal.test:65536', 'portal.test:999999999999999999999999',
            'portal.test:+80', 'portal.test:-80', 'portal.test:80.0', 'portal.test:8e1',
            'portal.test:0x50', "portal.test: 80", 'portal.test:', 'portal.test:port',
            'portal.test:80:90', '[2001:db8::10]:0', '[2001:db8::10]:65536',
            '[2001:db8::10]:80:90', '[2001:db8::10]:',
        ] as $host) {
            yield $host => [$host];
        }
    }

    public function test_malformed_host_is_rejected_without_uri_parser_bypass(): void
    {
        $request = Request::create('/api/security-test/public');
        $request->server->set('HTTP_HOST', '999.1.1.1');

        $response = (new TrustConfiguredHosts())->handle(
            $request,
            fn () => response()->json(['message' => 'bypassed'])
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['message' => 'Invalid request host.'], $response->getData(true));
        $this->assertStringNotContainsString('999.1.1.1', $response->getContent());
    }

    public function test_hsts_is_opt_in_and_https_only(): void
    {
        config(['security.hsts_enabled' => true]);
        $this->snapshotRequest(fn () => $this->get('/api/security-test/public'))
            ->assertHeaderMissing('Strict-Transport-Security');
        $this->snapshotRequest(fn () => $this->get('https://localhost/api/security-test/public'))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    private function assertSecurityHeaders($response): void
    {
        $response->assertHeader('Content-Security-Policy', self::PRODUCTION_CSP);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->assertHeaderMissing('X-Powered-By');
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    private function assertGenericApiFailure(callable $request, array $sensitiveMarkers): void
    {
        $response = $this->snapshotRequest($request)
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'An unexpected error occurred.']);
        $this->assertSecurityHeaders($response);

        $serialized = strtolower($response->getContent().json_encode($response->headers->all()));
        foreach ($sensitiveMarkers as $marker) {
            $this->assertStringNotContainsString(strtolower($marker), $serialized);
        }
    }

    private function assertGenericAppliedResponse($response, array $sensitiveMarkers): void
    {
        $response->assertStatus(500)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['message' => 'An unexpected error occurred.']);
        $this->assertSecurityHeaders($response);

        $serialized = strtolower($response->getContent().json_encode($response->headers->all()));
        foreach ($sensitiveMarkers as $marker) {
            $this->assertStringNotContainsString(strtolower($marker), $serialized);
        }
    }

    private function applyResponseForRoute(array|callable $action, $response, array $middleware = [])
    {
        $route = new \Illuminate\Routing\Route(['GET'], 'api/security-test/allowlist', $action);
        $route->middleware($middleware);
        $request = Request::create('/api/security-test/allowlist');
        $request->setRouteResolver(fn () => $route);

        return \Illuminate\Testing\TestResponse::fromBaseResponse(
            SecurityHeaders::applyTo($response, $request)
        );
    }

    private function applyResponse($response, string $path)
    {
        return \Illuminate\Testing\TestResponse::fromBaseResponse(
            SecurityHeaders::applyTo($response, Request::create($path))
        );
    }

    private function snapshotRequest(callable $request)
    {
        $before = $this->snapshot();
        $response = $request();
        $this->assertSame($before, $this->snapshot());

        return $response;
    }

    private function getWithHost(string $host)
    {
        return $this->call('GET', 'http://'.$host.'/api/security-test/public', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    private function snapshot(): array
    {
        return [
            'documents' => Schema::getConnection()->table('documents')->orderBy('id')->get(['id', 'status'])->map(fn ($row) => [(int) $row->id, (string) $row->status])->all(),
            'routes' => Schema::getConnection()->table('document_routes')->orderBy('id')->get(['id', 'document_id'])->map(fn ($row) => [(int) $row->id, (int) $row->document_id])->all(),
            'processing' => Schema::getConnection()->table('document_processing_logs')->orderBy('id')->get(['id', 'document_id'])->map(fn ($row) => [(int) $row->id, (int) $row->document_id])->all(),
            'qr' => Schema::getConnection()->table('document_qr_codes')->orderBy('id')->get(['id', 'status'])->map(fn ($row) => [(int) $row->id, (string) $row->status])->all(),
            'audits' => Schema::getConnection()->table('audit_logs')->orderBy('id')->get(['id', 'action'])->map(fn ($row) => [(int) $row->id, (string) $row->action])->all(),
            'audit_count' => Schema::getConnection()->table('audit_logs')->count(),
            'tokens' => Schema::getConnection()->table('personal_access_tokens')->orderBy('id')->get(['id', 'tokenable_type', 'tokenable_id', 'name', 'abilities'])->map(fn ($row) => [(int) $row->id, (string) $row->tokenable_type, (int) $row->tokenable_id, (string) $row->name, $row->abilities === null ? null : json_decode($row->abilities, true, 512, JSON_THROW_ON_ERROR)])->all(),
            'token_count' => Schema::getConnection()->table('personal_access_tokens')->count(),
            'attachments' => Schema::getConnection()->table('document_attachments')->count(),
        ];
    }

    private function registerIsolatedRoutes(): void
    {
        $router = app('router');
        $applicationRoutes = $router->getRoutes();
        $router->setRoutes(new \Illuminate\Routing\RouteCollection());

        Route::get('/api/security-test/public', fn () => response()->json(['message' => 'public']));
        Route::get('/api/security-test/protected', fn () => response()->json(['message' => 'protected']))->middleware('auth:sanctum');
        Route::get('/api/security-test/forbidden', fn () => abort(403, 'Expected forbidden'));
        Route::get('/api/security-test/not-found', fn () => abort(404, 'Expected missing'));
        Route::get('/api/security-test/conflict', fn () => response()->json(['message' => 'Expected conflict'], 409));
        Route::get('/api/security-test/safe-response-exception', fn () => throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(['message' => 'Expected gone'], 410)
        ));
        Route::get('/api/security-test/validation', function (Request $request) {
            $request->validate(['required_value' => ['required', 'string']]);
        });
        Route::get('/api/security-test/throttled', fn () => response()->json(['message' => 'Expected throttle'], 429));
        Route::get('/api/security-test/failure', fn () => throw new \RuntimeException('Synthetic secret failure'));
        Route::get('/api/security-test/failure-abort', fn () => abort(500, 'Sensitive explicit abort detail'));
        Route::get('/api/security-test/failure-response-json', fn () => throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(['message' => 'Sensitive response JSON'], 500, ['X-Sensitive-Diagnostic' => 'JSON marker'])
        ));
        Route::get('/api/security-test/failure-response-html', fn () => throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response('<p>Sensitive response HTML</p>', 500, ['X-Sensitive-Diagnostic' => 'HTML marker'])
        ));
        Route::get('/api/security-test/failure-response-503', fn () => throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(['message' => 'Sensitive response 503'], 503, ['X-Sensitive-Diagnostic' => '503 marker'])
        ));
        Route::get('/security-test-failure', fn () => throw new \RuntimeException('Synthetic HTML failure'));
        Route::get('/security-test-redirect', fn () => redirect('/'));

        foreach ($applicationRoutes as $route) {
            $router->getRoutes()->add($route);
        }
    }

    private function createSnapshotSchema(): void
    {
        Schema::create('documents', function (Blueprint $table): void { $table->id(); $table->string('status'); });
        Schema::create('document_routes', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('document_id'); });
        Schema::create('document_processing_logs', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('document_id'); });
        Schema::create('document_qr_codes', function (Blueprint $table): void { $table->id(); $table->string('status'); });
        Schema::create('audit_logs', function (Blueprint $table): void { $table->id(); $table->string('action'); });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id(); $table->string('tokenable_type'); $table->unsignedBigInteger('tokenable_id');
            $table->string('name'); $table->string('token')->unique(); $table->text('abilities')->nullable(); $table->timestamps();
        });
        Schema::create('document_attachments', function (Blueprint $table): void { $table->id(); });
    }
}
