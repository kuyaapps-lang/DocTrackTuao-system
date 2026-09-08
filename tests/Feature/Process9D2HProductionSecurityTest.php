<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Console\Commands\PruneExpired;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Process9D2HProductionSecurityTest extends TestCase
{
    public function test_pruning_command_schedule_and_local_mutex(): void
    {
        $this->assertInstanceOf(PruneExpired::class, Artisan::all()['sanctum:prune-expired']);
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($event) => str_contains($event->command ?? '', 'sanctum:prune-expired')
        ));
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(ConsoleApplication::formatCommandString('sanctum:prune-expired --hours=24'), $event->command);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertSame('UTC', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(1440, $event->expiresAt);
        $clock = Carbon::getTestNow();
        try {
            Carbon::setTestNow(Carbon::parse('2026-09-08 00:00:00', 'UTC'));
            $this->assertTrue($event->isDue($this->app));
            Carbon::setTestNow(Carbon::parse('2026-09-08 00:01:00', 'UTC'));
            $this->assertFalse($event->isDue($this->app));
            $this->assertFalse($event->shouldSkipDueToOverlapping());
            $this->assertTrue($event->shouldSkipDueToOverlapping());
        } finally {
            $event->mutex->forget($event);
            Carbon::setTestNow($clock);
        }
        // Application teardown discards the schedule/config container; no schedule is run.
    }

    public function test_pruning_preserves_retained_tokens_and_every_business_row(): void
    {
        $this->createIsolatedSchema();
        $this->assertSame(480, config('authentication.token_lifetime_minutes'));
        $this->assertSame(480, config('sanctum.expiration'));
        $tables = ['users', 'documents', 'document_routes', 'document_processing_logs',
            'document_qr_codes', 'document_attachments', 'audit_logs'];
        foreach ($tables as $table) {
            DB::table($table)->insert(['id' => 1, 'name' => 'unchanged-'.$table]);
        }
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [
            $table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ])->all();
        $before = $snapshot();
        $clock = Carbon::getTestNow();
        try {
            Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00', 'UTC'));
            // Each expiry branch is tested independently, including its strict boundary.
            $cases = [
                'old-explicit' => [now()->subHour(), now()->subHours(24)->subSecond(), false],
                'old-configured' => [now()->subMinutes(480 + 1440)->subSecond(), null, false],
                'active' => [now()->subMinute(), now()->addMinutes(479), true],
                'active-no-explicit' => [now()->subMinute(), null, true],
                'recent-explicit' => [now()->subHour(), now()->subMinute(), true],
                'recent-configured' => [now()->subMinutes(481), null, true],
                'explicit-boundary' => [now()->subHour(), now()->subHours(24), true],
                'configured-boundary' => [now()->subMinutes(480 + 1440), null, true],
            ];
            $retained = [];
            foreach ($cases as $name => [$created, $expires, $keep]) {
                $token = PersonalAccessToken::forceCreate([
                    'tokenable_type' => User::class, 'tokenable_id' => 1,
                    'name' => $name, 'token' => hash('sha256', $name), 'abilities' => ['*'],
                    'created_at' => $created, 'updated_at' => $created, 'expires_at' => $expires,
                ]);
                if ($keep) {
                    $retained[] = $token->id;
                }
            }
            $beforeTokens = DB::table('personal_access_tokens')->whereIn('id', $retained)->orderBy('id')->get()->toJson();
            $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();
            $this->assertSame($retained, PersonalAccessToken::orderBy('id')->pluck('id')->all());
            $this->assertSame($beforeTokens, DB::table('personal_access_tokens')->orderBy('id')->get()->toJson());
            $this->assertSame($before, $snapshot());
        } finally {
            Carbon::setTestNow($clock);
        }
    }

    public function test_cors_configuration_is_explicit_and_has_no_wildcards(): void
    {
        $this->assertEquals([
            'paths' => ['api/*'],
            'allowed_origins' => [],
            'allowed_origins_patterns' => [],
            'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],
            'exposed_headers' => [], 'max_age' => 0, 'supports_credentials' => false,
        ], config('cors'));
    }

    #[DataProvider('hostileOrigins')]
    public function test_hostile_preflight_and_actual_requests_have_no_cors_permission(string $origin): void
    {
        $headers = ['Origin' => $origin];
        $preflight = $this->withHeaders($headers + [
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type,x-hostile',
        ])->options('/api/login')->assertNoContent();
        $this->assertNoCorsPermission($preflight);
        $this->assertSecurityHeaders($preflight);

        // Existing public validation and protected authentication paths require no database.
        $this->flushHeaders();
        $public = $this->withHeaders($headers)->postJson('/api/login', [])->assertUnprocessable();
        $protected = $this->withHeaders($headers)->getJson('/api/user')->assertUnauthorized();
        $simple = $this->withHeaders($headers)->get('/api/user', ['Accept' => 'application/json'])->assertUnauthorized();
        foreach ([$public, $protected, $simple] as $response) {
            $this->assertNoCorsPermission($response);
            $this->assertSecurityHeaders($response);
        }
    }

    public static function hostileOrigins(): iterable
    {
        foreach (['https://hostile.test', 'null', 'http://localhost.hostile.test',
            'http://localhost:5173', 'http://127.0.0.1:8001'] as $origin) {
            yield $origin => [$origin];
        }
    }

    public function test_no_origin_clients_keep_public_and_protected_statuses(): void
    {
        foreach ([$this->postJson('/api/login', [])->assertUnprocessable(),
            $this->getJson('/api/user')->assertUnauthorized()] as $response) {
            $this->assertNoCorsPermission($response);
            $this->assertSecurityHeaders($response);
        }
    }

    #[DataProvider('compatibleOrigins')]
    public function test_real_bearer_authentication_remains_compatible(?string $origin): void
    {
        $this->createIsolatedSchema();
        $user = User::forceCreate(['name' => 'Isolated bearer user']);
        $token = $user->createToken('isolated-test', ['*'], now()->addMinutes(480));
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];
        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }
        $response = $this->withHeaders($headers)->getJson('/api/user')->assertOk()
            ->assertJsonPath('id', $user->id);
        $this->assertNoCorsPermission($response);
        $this->assertSecurityHeaders($response);
    }

    public static function compatibleOrigins(): iterable
    {
        yield 'same origin' => ['http://localhost'];
        yield 'non-browser' => [null];
    }

    private function assertNoCorsPermission($response): void
    {
        foreach (['Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials',
            'Access-Control-Expose-Headers', 'Access-Control-Allow-Headers',
            'Access-Control-Allow-Methods'] as $header) {
            $response->assertHeaderMissing($header);
        }
    }

    private function assertSecurityHeaders($response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeaderMissing('Strict-Transport-Security')
            ->assertHeaderMissing('X-Powered-By');
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    private function createIsolatedSchema(): void
    {
        // Fail before schema access if the runner is not using the isolated test database.
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->timestamps();
        });
        foreach (['roles', 'offices', 'departments', 'documents', 'document_routes',
            'document_processing_logs', 'document_qr_codes', 'document_attachments', 'audit_logs'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name');
            });
        }
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        // Laravel TestCase tears down this application's in-memory connection, config,
        // auth guards, facade instances and routes after each test. No persistent schema.
    }
}
