<?php

namespace Tests\Feature;

use App\Support\PublicLookupSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Process9C2BPublicLookupSecurityTest extends TestCase
{
    private int $documentId;

    private array $limiterBuckets = [];

    private bool $publicRequestInProgress = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'public_access.rate_limits.tracking.max_attempts' => 3,
            'public_access.rate_limits.tracking.decay_seconds' => 60,
            'public_access.rate_limits.qr.max_attempts' => 3,
            'public_access.rate_limits.qr.decay_seconds' => 60,
        ]);

        $this->createSchema();
        $this->seedSafeFixtures();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        foreach (array_keys($this->limiterBuckets) as $bucket) {
            RateLimiter::clear($bucket);
        }

        foreach ([
            'audit_logs', 'personal_access_tokens', 'document_qr_codes',
            'document_processing_logs', 'document_routes', 'documents',
            'confidentiality_levels', 'priorities', 'document_statuses',
            'document_types', 'offices',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_tracking_accepts_established_bounds_and_preserves_public_contract(): void
    {
        $shortest = 'A';
        $longest = str_repeat('A', PublicLookupSecurity::TRACKING_MAX_LENGTH);
        $legacy = 'LEGACY-2026';

        foreach ([$shortest, $longest, $legacy] as $trackingNo) {
            $this->insertDocument($trackingNo, 'Safe public title', 'Safe details');
            $this->getJson('/api/track/'.$trackingNo, $this->ipHeaders($trackingNo))
                ->assertOk()
                ->assertJsonPath('tracking_no', $trackingNo)
                ->assertJsonMissingPath('processing_note')
                ->assertJsonMissingPath('attachments');
        }

        $this->getJson('/api/track/DOC-20260902123456123', $this->ipHeaders('generated'))
            ->assertOk();

        $protected = $this->insertDocument(
            'PROTECTED-1',
            'Suppressed title',
            'Suppressed details',
            2
        );

        $this->getJson('/api/track/PROTECTED-1', $this->ipHeaders('protected'))
            ->assertOk()
            ->assertJsonPath('title', 'Protected Document')
            ->assertJsonPath('details', null)
            ->assertJsonPath('is_protected', true)
            ->assertJsonMissing(['Suppressed title', 'Suppressed details'])
            ->assertJsonPath('tracking_no', $protected['tracking_no']);
    }

    public function test_tracking_rejects_malformed_and_unknown_values_with_one_safe_response(): void
    {
        $expected = ['message' => 'Document tracking number not found.'];
        $values = [
            str_repeat('A', PublicLookupSecurity::TRACKING_MAX_LENGTH + 1),
            '%20', 'HAS%20SPACE', 'HAS%09TAB', 'HAS%00CONTROL',
            'HAS%2FSLASH', 'HAS%5CBACKSLASH', 'HAS%3FQUERY', 'HAS%23FRAGMENT',
            'BAD_TOKEN', rawurlencode('DОC-1'), 'UNKNOWN-1',
        ];

        foreach ($values as $index => $value) {
            $this->getJson('/api/track/'.$value, $this->ipHeaders('tracking-'.$index))
                ->assertNotFound()
                ->assertExactJson($expected);
        }

        $this->assertFalse(PublicLookupSecurity::validTrackingNumber('HAS/SLASH'));
        $this->assertFalse(PublicLookupSecurity::validTrackingNumber('HAS\\BACKSLASH'));
        $this->assertFalse(PublicLookupSecurity::validTrackingNumber(''));
    }

    public function test_qr_accepts_current_and_uuid_formats_and_preserves_all_states(): void
    {
        $current = 'ABCDE-2345678';
        $uuid = '123e4567-e89b-42d3-a456-426614174000';
        $registered = 'FGHJK-89ABCDE';
        $void = 'MNPQR-2345678';
        $unexpected = 'STUVW-2345678';

        $this->insertQr($current, 'unused');
        $this->insertQr($uuid, 'unused');
        $this->insertQr($registered, 'registered', $this->documentId);
        $this->insertQr($void, 'void');
        $this->insertQr($unexpected, 'quarantined');

        $this->getJson('/api/q/'.$current, $this->ipHeaders('qr-current'))
            ->assertOk()
            ->assertJsonPath('registration_path', '/register-document/'.$current);
        $this->getJson('/api/q/'.$uuid, $this->ipHeaders('qr-uuid'))
            ->assertOk();
        $this->getJson('/api/q/'.$registered, $this->ipHeaders('qr-registered'))
            ->assertOk()
            ->assertJsonPath('tracking_path', '/track/DOC-20260902123456123');
        $this->getJson('/api/q/'.$void, $this->ipHeaders('qr-void'))
            ->assertStatus(410)
            ->assertJsonPath('state', 'void');
        $this->getJson('/api/q/'.$unexpected, $this->ipHeaders('qr-conflict'))
            ->assertConflict()
            ->assertJsonMissingPath('generated_by');
    }

    public function test_qr_rejects_malformed_and_unknown_values_with_one_safe_response(): void
    {
        $expected = [
            'state' => 'invalid',
            'message' => 'The QR code is invalid or does not exist.',
        ];
        $values = [
            'ABCD-2345678', 'ABCDEF-2345678',
            str_repeat('A', PublicLookupSecurity::QR_MAX_LENGTH + 1),
            '%20', 'ABCDE%202345678', 'ABCDE%092345678', 'ABCDE%002345678',
            'ABCDE%2F2345678', 'ABCDE%5C2345678', 'ABCDE%3F2345678', 'ABCDE%232345678',
            'ABCDE_2345678', rawurlencode('АBCDE-2345678'), 'ZZZZZ-9999999',
        ];

        foreach ($values as $index => $value) {
            $this->getJson('/api/q/'.$value, $this->ipHeaders('qr-bad-'.$index))
                ->assertNotFound()
                ->assertExactJson($expected);
        }

        $this->assertFalse(PublicLookupSecurity::validQrToken('ABCDE/2345678'));
        $this->assertFalse(PublicLookupSecurity::validQrToken('ABCDE\\2345678'));
        $this->assertFalse(PublicLookupSecurity::validQrToken(''));
    }

    public function test_malformed_identifiers_do_not_query_lookup_tables(): void
    {
        $queriedDocuments = false;
        $queriedQrCodes = false;

        DB::listen(function ($query) use (&$queriedDocuments, &$queriedQrCodes): void {
            if (!$this->publicRequestInProgress) {
                return;
            }

            $sql = strtolower($query->sql);
            $queriedDocuments = $queriedDocuments || str_contains($sql, 'from "documents"');
            $queriedQrCodes = $queriedQrCodes || str_contains($sql, 'from "document_qr_codes"');
        });

        $this->getJson('/api/track/BAD_VALUE', $this->ipHeaders('no-query-track'))
            ->assertNotFound();
        $this->assertFalse($queriedDocuments);

        $this->getJson('/api/q/BAD_VALUE', $this->ipHeaders('no-query-qr'))
            ->assertNotFound();
        $this->assertFalse($queriedQrCodes);
    }

    public function test_tracking_rate_limit_counts_all_outcomes_and_returns_safe_429(): void
    {
        $headers = $this->ipHeaders('tracking-limit');
        $before = $this->completeSnapshot();

        $this->getJson('/api/track/DOC-20260902123456123', $headers)->assertOk();
        $this->getJson('/api/track/UNKNOWN-1', $headers)->assertNotFound();
        $this->getJson('/api/track/BAD_VALUE', $headers)->assertNotFound();
        $response = $this->getJson('/api/track/DOC-20260902123456123', $headers)
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => 'Too many requests. Please try again later.',
            ])
            ->assertHeader('Retry-After');

        $this->assertSafeThrottleResponse($response->getContent(), $headers['REMOTE_ADDR']);
        $this->assertSame($before, $this->completeSnapshot());
    }

    public function test_qr_rate_limit_counts_all_outcomes_and_returns_safe_429(): void
    {
        $unused = 'ABCDE-7654328';
        $void = 'FGHJK-7654328';
        $this->insertQr($unused, 'unused');
        $this->insertQr($void, 'void');
        $headers = $this->ipHeaders('qr-limit');
        $before = $this->completeSnapshot();

        $this->getJson('/api/q/'.$unused, $headers)->assertOk();
        $this->getJson('/api/q/'.$void, $headers)->assertStatus(410);
        $this->getJson('/api/q/BAD_VALUE', $headers)->assertNotFound();
        $response = $this->getJson('/api/q/'.$unused, $headers)
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => 'Too many requests. Please try again later.',
            ])
            ->assertHeader('Retry-After');

        $this->assertSafeThrottleResponse($response->getContent(), $headers['REMOTE_ADDR']);
        $this->assertSame($before, $this->completeSnapshot());
    }

    public function test_limiter_buckets_are_isolated_by_endpoint_and_ip(): void
    {
        $firstIp = $this->ipHeaders('first-ip');
        $secondIp = $this->ipHeaders('second-ip');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->getJson('/api/track/UNKNOWN-1', $firstIp)->assertNotFound();
        }

        $this->getJson('/api/track/UNKNOWN-1', $firstIp)->assertTooManyRequests();
        $this->getJson('/api/track/UNKNOWN-1', $secondIp)->assertNotFound();
        $this->getJson('/api/q/ZZZZZ-9999999', $firstIp)->assertNotFound();
    }

    public function test_limiter_decay_resets_deterministically(): void
    {
        CarbonImmutable::setTestNow('2026-09-02 10:00:00');
        $headers = $this->ipHeaders('decay');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->getJson('/api/track/UNKNOWN-1', $headers)->assertNotFound();
        }
        $this->getJson('/api/track/UNKNOWN-1', $headers)->assertTooManyRequests();

        CarbonImmutable::setTestNow('2026-09-02 10:01:01');
        $this->getJson('/api/track/UNKNOWN-1', $headers)->assertNotFound();
    }

    public function test_invalid_rate_limit_configuration_fails_closed_without_lookup(): void
    {
        config(['public_access.rate_limits.tracking.max_attempts' => 'invalid']);
        $queriedDocuments = false;
        DB::listen(function ($query) use (&$queriedDocuments): void {
            if ($this->publicRequestInProgress) {
                $queriedDocuments = $queriedDocuments ||
                    str_contains(strtolower($query->sql), 'from "documents"');
            }
        });

        $this->getJson('/api/track/DOC-20260902123456123', $this->ipHeaders('bad-config'))
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Public lookup is temporarily unavailable.',
            ]);

        $this->assertFalse($queriedDocuments);
    }

    public function test_invalid_qr_attempts_configuration_is_contained_and_tracking_remains_operational(): void
    {
        $invalidValue = 'invalid-qr-policy';
        $qrToken = 'BCDFG-7654328';
        $trackingNumber = 'DOC-20260902123456123';
        $rawIp = '198.51.100.90';
        $headers = ['REMOTE_ADDR' => $rawIp];
        config(['public_access.rate_limits.qr.max_attempts' => $invalidValue]);
        $queriedQrCodes = false;
        DB::listen(function ($query) use (&$queriedQrCodes): void {
            if ($this->publicRequestInProgress) {
                $queriedQrCodes = $queriedQrCodes ||
                    str_contains(strtolower($query->sql), 'from "document_qr_codes"');
            }
        });

        $qrResponse = $this->getJson('/api/q/'.$qrToken, $headers)
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Public lookup is temporarily unavailable.',
            ]);
        $this->assertFalse($queriedQrCodes);

        $applicationKey = PublicLookupSecurity::limiterKeyForIp($rawIp, 'qr');
        $frameworkKey = md5(PublicLookupSecurity::QR_LIMITER.$applicationKey);
        $serializedResponse = strtolower(
            $qrResponse->getContent().json_encode($qrResponse->headers->all())
        );
        foreach ([
            $qrToken, $invalidValue, 'PUBLIC_QR_MAX_ATTEMPTS', $rawIp,
            $applicationKey, $frameworkKey, 'sql', 'exception', 'trace',
            'redis', 'memcached', 'database cache', 'filesystem',
            'c:\\', '/var/', '/home/',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                strtolower($forbidden),
                $serializedResponse
            );
        }

        $this->getJson('/api/track/'.$trackingNumber, $headers)
            ->assertOk()
            ->assertJsonPath('tracking_no', $trackingNumber);
    }

    #[DataProvider('invalidConfigurationValues')]
    public function test_each_invalid_configuration_form_fails_closed(mixed $invalid): void
    {
        config(['public_access.rate_limits.tracking.max_attempts' => $invalid]);
        $queriedDocuments = false;
        DB::listen(function ($query) use (&$queriedDocuments): void {
            if ($this->publicRequestInProgress) {
                $queriedDocuments = $queriedDocuments ||
                    str_contains(strtolower($query->sql), 'from "documents"');
            }
        });

        $this->getJson('/api/track/DOC-20260902123456123', $this->ipHeaders('bad-policy'))
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Public lookup is temporarily unavailable.',
            ]);
        $this->assertFalse($queriedDocuments);
    }

    public static function invalidConfigurationValues(): iterable
    {
        yield 'negative integer' => [-1];
        yield 'negative string' => ['-1'];
        yield 'boolean true' => [true];
        yield 'boolean false' => [false];
        yield 'string true' => ['true'];
        yield 'string false' => ['false'];
        yield 'leading whitespace' => [' 1'];
        yield 'trailing whitespace' => ['1 '];
        yield 'whitespace only' => [' '];
        yield 'plus prefix' => ['+1'];
        yield 'fractional float' => [1.5];
        yield 'fractional string' => ['1.5'];
        yield 'exponent notation' => ['1e2'];
        yield 'integer zero' => [0];
        yield 'string zero' => ['0'];
        yield 'leading zero' => ['01'];
        yield 'hexadecimal string' => ['0x10'];
        yield 'binary string' => ['0b10'];
        yield 'digit separator string' => ['1_000'];
        yield 'above safe bound' => [1001];
        yield 'array' => [[]];
        yield 'object' => [(object) []];
        yield 'null' => [null];
        yield 'empty string' => [''];
    }

    public function test_configuration_accepts_only_bounded_integers_and_canonical_decimal_strings(): void
    {
        foreach ([1, 1000, '1', '1000'] as $accepted) {
            config(['public_access.rate_limits.tracking.max_attempts' => $accepted]);
            $this->assertSame(
                (int) $accepted,
                PublicLookupSecurity::limiterPolicy('tracking')['max_attempts']
            );
        }

        foreach ([1, 3600, '1', '3600'] as $accepted) {
            config(['public_access.rate_limits.tracking.decay_seconds' => $accepted]);
            $this->assertSame(
                (int) $accepted,
                PublicLookupSecurity::limiterPolicy('tracking')['decay_seconds']
            );
        }
    }

    public function test_limiter_keys_ignore_path_values_and_policy_bounds_are_enforced(): void
    {
        $first = Request::create('/api/track/FIRST-1', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.40',
        ]);
        $second = Request::create('/api/track/BAD_VALUE', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.40',
        ]);
        $otherIp = Request::create('/api/track/FIRST-1', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.41',
        ]);

        $trackingKey = PublicLookupSecurity::limiterKey($first, 'tracking');
        $this->assertSame(
            $trackingKey,
            PublicLookupSecurity::limiterKey($second, 'tracking')
        );
        $this->assertNotSame(
            $trackingKey,
            PublicLookupSecurity::limiterKey($first, 'qr')
        );
        $this->assertNotSame(
            $trackingKey,
            PublicLookupSecurity::limiterKey($otherIp, 'tracking')
        );
        $this->assertSame(64, strlen($trackingKey));
        $this->assertStringNotContainsString('198.51.100.40', $trackingKey);
        $this->assertStringNotContainsString('FIRST-1', $trackingKey);

        foreach ([0, 1001, '1.5', 'invalid'] as $invalid) {
            config(['public_access.rate_limits.tracking.max_attempts' => $invalid]);
            $this->assertNull(PublicLookupSecurity::limiterPolicy('tracking'));
        }
        config(['public_access.rate_limits.tracking.max_attempts' => 3]);
        foreach ([0, 3601, '1.5', 'invalid'] as $invalid) {
            config(['public_access.rate_limits.tracking.decay_seconds' => $invalid]);
            $this->assertNull(PublicLookupSecurity::limiterPolicy('tracking'));
        }
    }

    public function test_client_ip_canonicalization_is_stable_isolated_and_opaque(): void
    {
        $ipv4 = PublicLookupSecurity::limiterKeyForIp('198.51.100.40', 'tracking');
        $sameIpv4 = PublicLookupSecurity::limiterKeyForIp('198.51.100.40', 'tracking');
        $otherIpv4 = PublicLookupSecurity::limiterKeyForIp('198.51.100.41', 'tracking');
        $compressedIpv6 = PublicLookupSecurity::limiterKeyForIp('2001:db8::1', 'tracking');
        $expandedIpv6 = PublicLookupSecurity::limiterKeyForIp(
            '2001:0db8:0000:0000:0000:0000:0000:0001',
            'tracking'
        );
        $otherIpv6 = PublicLookupSecurity::limiterKeyForIp('2001:db8::2', 'tracking');

        $this->assertSame($ipv4, $sameIpv4);
        $this->assertNotSame($ipv4, $otherIpv4);
        $this->assertSame($compressedIpv6, $expandedIpv6);
        $this->assertNotSame($compressedIpv6, $otherIpv6);
        $this->assertNotSame($ipv4, $compressedIpv6);

        $sentinel = PublicLookupSecurity::limiterKeyForIp(null, 'tracking');
        foreach (['', 'not-an-ip', [], new \stdClass()] as $invalid) {
            $this->assertSame(
                $sentinel,
                PublicLookupSecurity::limiterKeyForIp($invalid, 'tracking')
            );
        }

        foreach ([$ipv4, $compressedIpv6, $sentinel] as $key) {
            $this->assertSame(64, strlen($key));
            $this->assertStringNotContainsString('198.51.100.40', $key);
            $this->assertStringNotContainsString('2001:db8', $key);
        }
    }

    public function test_application_and_framework_limiter_keys_and_logs_remain_opaque(): void
    {
        $rawIp = '198.51.100.40';
        $identifier = 'UNKNOWN-OPAQUE-1';
        $applicationKey = PublicLookupSecurity::limiterKeyForIp($rawIp, 'tracking');
        $storedKey = md5(PublicLookupSecurity::TRACKING_LIMITER.$applicationKey);
        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = strtolower($event->message.json_encode($event->context));
        });

        $response = $this->getJson(
            '/api/track/'.$identifier,
            ['REMOTE_ADDR' => $rawIp]
        )->assertNotFound();

        $this->assertSame(1, RateLimiter::attempts($storedKey));
        $this->assertSame(0, RateLimiter::attempts($applicationKey));
        foreach ([$applicationKey, $storedKey, strtolower($response->getContent()), ...$logged] as $value) {
            $this->assertStringNotContainsString(strtolower($rawIp), $value);
            $this->assertStringNotContainsString(strtolower($identifier), $value);
        }
    }

    #[DataProvider('hyphenGapTrackingValues')]
    public function test_tracking_hyphen_gaps_are_generic_query_free_and_rate_limited(
        string $malformed
    ): void {
        config(['public_access.rate_limits.tracking.max_attempts' => 1]);
        $queriedDocuments = false;
        DB::listen(function ($query) use (&$queriedDocuments): void {
            if ($this->publicRequestInProgress) {
                $queriedDocuments = $queriedDocuments ||
                    str_contains(strtolower($query->sql), 'from "documents"');
            }
        });
        $headers = $this->ipHeaders('hyphen-gap');

        $this->getJson('/api/track/'.$malformed, $headers)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Document tracking number not found.']);
        $this->assertFalse($queriedDocuments);
        $this->getJson('/api/track/DOC-20260902123456123', $headers)
            ->assertTooManyRequests();
    }

    public static function hyphenGapTrackingValues(): iterable
    {
        yield 'leading hyphen' => ['-LEADING'];
        yield 'trailing hyphen' => ['TRAILING-'];
        yield 'adjacent hyphens' => ['REPEATED--HYPHEN'];
    }

    public function test_all_natural_qr_outcomes_share_one_bucket_without_affecting_tracking(): void
    {
        config(['public_access.rate_limits.qr.max_attempts' => 6]);
        $registered = 'BCDFG-2345678';
        $unexpected = 'HJKMN-2345678';
        $unused = 'NPQRS-2345678';
        $void = 'TUVWX-2345678';
        $this->insertQr($registered, 'registered', $this->documentId);
        $this->insertQr($unexpected, 'quarantined');
        $this->insertQr($unused, 'unused');
        $this->insertQr($void, 'void');
        $headers = $this->ipHeaders('all-qr-outcomes');

        $this->getJson('/api/q/'.$registered, $headers)->assertOk();
        $this->getJson('/api/q/'.$unexpected, $headers)->assertConflict();
        $this->getJson('/api/q/ZZZZZ-9999999', $headers)->assertNotFound();
        $this->getJson('/api/q/BAD_VALUE', $headers)->assertNotFound();
        $this->getJson('/api/q/'.$unused, $headers)->assertOk();
        $this->getJson('/api/q/'.$void, $headers)->assertStatus(410);
        $this->getJson('/api/q/'.$unused, $headers)
            ->assertTooManyRequests()
            ->assertExactJson([
                'message' => 'Too many requests. Please try again later.',
            ]);

        $this->getJson('/api/track/UNKNOWN-1', $headers)->assertNotFound();
    }

    private function assertSafeThrottleResponse(string $content, string $rawIp): void
    {
        foreach ([
            strtolower($rawIp),
            PublicLookupSecurity::limiterKeyForIp($rawIp, 'tracking'),
            PublicLookupSecurity::limiterKeyForIp($rawIp, 'qr'),
            'cache', 'sql', 'exception', 'trace', 'identifier',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($content));
        }
    }

    private function ipHeaders(string $seed): array
    {
        $octet = (hexdec(substr(hash('sha256', $seed), 0, 2)) % 250) + 1;

        return ['REMOTE_ADDR' => '198.51.100.'.$octet];
    }

    public function getJson($uri, array $headers = [], $options = 0)
    {
        $path = parse_url((string) $uri, PHP_URL_PATH) ?: '';
        $category = str_starts_with($path, '/api/q/') ? 'qr' : 'tracking';
        $limiterName = $category === 'qr'
            ? PublicLookupSecurity::QR_LIMITER
            : PublicLookupSecurity::TRACKING_LIMITER;
        $applicationKey = PublicLookupSecurity::limiterKeyForIp(
            $headers['REMOTE_ADDR'] ?? null,
            $category
        );
        // Named ThrottleRequests applies MD5 to the already opaque SHA-256 key.
        $storedKey = md5($limiterName.$applicationKey);
        $this->limiterBuckets[$storedKey] = true;
        $before = isset($this->documentId) ? $this->completeSnapshot() : [];

        $this->publicRequestInProgress = true;
        try {
            $response = parent::getJson($uri, $headers, $options);
        } finally {
            $this->publicRequestInProgress = false;
        }

        if (isset($this->documentId)) {
            $this->assertSame($before, $this->completeSnapshot());
        }

        return $response;
    }

    private function seedSafeFixtures(): void
    {
        DB::table('document_types')->insert(['id' => 1, 'type_name' => 'Memo']);
        DB::table('document_statuses')->insert(['id' => 1, 'status_name' => 'Pending']);
        DB::table('priorities')->insert(['id' => 1, 'priority_name' => 'Normal']);
        DB::table('confidentiality_levels')->insert([
            ['id' => 1, 'level_name' => 'Public'],
            ['id' => 2, 'level_name' => 'Confidential'],
        ]);
        DB::table('offices')->insert([
            'id' => 1, 'office_name' => 'Records', 'office_code' => 'REC',
        ]);

        $document = $this->insertDocument(
            'DOC-20260902123456123',
            'Public fixture title',
            'Public fixture details'
        );
        $this->documentId = $document['id'];
    }

    private function insertDocument(
        string $trackingNo,
        string $title,
        string $description,
        int $confidentialityId = 1
    ): array {
        $id = DB::table('documents')->insertGetId([
            'tracking_no' => $trackingNo,
            'title' => $title,
            'description' => $description,
            'status' => 'pending',
            'document_type_id' => 1,
            'status_id' => 1,
            'priority_id' => 1,
            'confidentiality_level_id' => $confidentialityId,
            'origin_office_id' => 1,
            'current_office_id' => 1,
            'document_date' => '2026-09-02',
            'created_at' => '2026-09-02 08:00:00',
            'updated_at' => '2026-09-02 08:00:00',
        ]);

        return ['id' => $id, 'tracking_no' => $trackingNo];
    }

    private function insertQr(string $token, string $status, ?int $documentId = null): void
    {
        DB::table('document_qr_codes')->insert([
            'qr_token' => $token,
            'status' => $status,
            'document_id' => $documentId,
            'generated_at' => '2026-09-02 08:00:00',
            'created_at' => '2026-09-02 08:00:00',
            'updated_at' => '2026-09-02 08:00:00',
        ]);
    }

    private function completeSnapshot(): array
    {
        $audits = $this->normalizedRows('audit_logs', [
            'id', 'user_id', 'module', 'action', 'record_id', 'description',
            'ip_address', 'user_agent', 'created_at', 'updated_at',
        ], ['id'], ['user_id', 'record_id'], [], [
            'description', 'ip_address', 'user_agent',
        ]);

        return [
            'documents' => $this->normalizedRows('documents', [
                'id', 'tracking_no', 'title', 'description', 'status',
                'document_type_id', 'status_id', 'priority_id',
                'confidentiality_level_id', 'origin_office_id',
                'current_office_id', 'current_action_id', 'processing_note',
                'current_action_updated_by', 'current_action_updated_at',
                'created_by', 'document_date', 'due_date', 'created_at', 'updated_at',
            ], ['id'], [
                'document_type_id', 'status_id', 'priority_id',
                'confidentiality_level_id', 'origin_office_id',
                'current_office_id', 'current_action_id',
                'current_action_updated_by', 'created_by',
            ], [], ['tracking_no', 'title', 'description', 'processing_note']),
            'routes' => $this->normalizedRows('document_routes', [
                'id', 'document_id', 'from_office_id', 'to_office_id',
                'forwarded_by', 'received_by', 'forwarded_at', 'received_at',
                'status_id', 'action_id', 'remarks', 'created_at', 'updated_at',
            ], ['id', 'document_id', 'from_office_id', 'to_office_id', 'forwarded_by', 'status_id'], ['received_by', 'action_id'], [], ['remarks']),
            'processing' => $this->normalizedRows('document_processing_logs', [
                'id', 'document_id', 'office_id', 'user_id',
                'processing_action_id', 'document_route_id', 'event_type',
                'processing_note', 'event_note', 'created_at', 'updated_at',
            ], ['id', 'document_id'], ['office_id', 'user_id', 'processing_action_id', 'document_route_id'], [], ['processing_note', 'event_note']),
            'qr_codes' => $this->normalizedRows('document_qr_codes', [
                'id', 'qr_token', 'status', 'document_id', 'generated_by',
                'generated_at', 'registered_at', 'created_at', 'updated_at',
            ], ['id'], ['document_id', 'generated_by'], [], ['qr_token']),
            'audits' => $audits,
            'audit_count' => count($audits),
            'tokens' => $this->normalizedRows('personal_access_tokens', [
                'id', 'tokenable_type', 'tokenable_id', 'name', 'abilities',
                'last_used_at', 'expires_at', 'created_at', 'updated_at',
            ], ['id', 'tokenable_id'], [], ['abilities']),
            'token_count' => DB::table('personal_access_tokens')->count(),
        ];
    }

    private function normalizedRows(
        string $table,
        array $columns,
        array $integerColumns = [],
        array $nullableIntegerColumns = [],
        array $jsonColumns = [],
        array $sensitiveColumns = []
    ): array {
        return DB::table($table)->orderBy('id')->get($columns)
            ->map(function ($row) use ($columns, $integerColumns, $nullableIntegerColumns, $jsonColumns, $sensitiveColumns): array {
                $normalized = [];

                foreach ($columns as $column) {
                    $value = $row->{$column};
                    if (in_array($column, $sensitiveColumns, true)) {
                        $value = $value === null
                            ? null
                            : 'sha256:'.hash('sha256', (string) $value);
                    } elseif (in_array($column, $integerColumns, true)) {
                        $value = (int) $value;
                    } elseif (in_array($column, $nullableIntegerColumns, true)) {
                        $value = $value === null ? null : (int) $value;
                    } elseif (in_array($column, $jsonColumns, true)) {
                        $value = $value === null ? null : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
                    } elseif ($value !== null) {
                        $value = (string) $value;
                    }
                    $normalized[$column] = $value;
                }

                return $normalized;
            })->all();
    }

    private function createSchema(): void
    {
        Schema::create('document_types', fn (Blueprint $table) => $this->lookupTable($table, 'type_name'));
        Schema::create('document_statuses', fn (Blueprint $table) => $this->lookupTable($table, 'status_name'));
        Schema::create('priorities', fn (Blueprint $table) => $this->lookupTable($table, 'priority_name'));
        Schema::create('confidentiality_levels', fn (Blueprint $table) => $this->lookupTable($table, 'level_name'));
        Schema::create('offices', function (Blueprint $table): void {
            $table->id();
            $table->string('office_name');
            $table->string('office_code');
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('tracking_no', 50)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            foreach (['document_type_id', 'status_id', 'priority_id', 'confidentiality_level_id', 'origin_office_id', 'current_office_id', 'current_action_id', 'current_action_updated_by', 'created_by'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            $table->text('processing_note')->nullable();
            $table->timestamp('current_action_updated_at')->nullable();
            $table->date('document_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
        });
        Schema::create('document_routes', function (Blueprint $table): void {
            $table->id();
            foreach (['document_id', 'from_office_id', 'to_office_id', 'forwarded_by', 'status_id'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('action_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
        Schema::create('document_processing_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            foreach (['office_id', 'user_id', 'processing_action_id', 'document_route_id'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            $table->string('event_type', 50);
            $table->text('processing_note')->nullable();
            $table->string('event_note', 1000)->nullable();
            $table->timestamps();
        });
        Schema::create('document_qr_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('qr_token', 36)->unique();
            $table->string('status', 20);
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module');
            $table->string('action');
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function lookupTable(Blueprint $table, string $name): void
    {
        $table->id();
        $table->string($name);
        $table->timestamps();
    }
}
