<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class Process9BAuthenticationSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('offices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('office_name');
            $table->string('office_code')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
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
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module');
            $table->string('action');
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        foreach ([
            'documents',
            'document_routes',
            'document_processing_logs',
            'document_qr_codes',
            'document_attachments',
        ] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
            });
        }

        config()->set('authentication.login_max_attempts', 5);
        config()->set('authentication.login_decay_seconds', 60);
        config()->set('authentication.token_lifetime_minutes', 480);
        config()->set('authentication.token_name', 'doctrack-spa');
        config()->set('sanctum.expiration', 480);
    }

    protected function tearDown(): void
    {
        foreach ([
            'document_attachments',
            'document_qr_codes',
            'document_processing_logs',
            'document_routes',
            'documents',
            'audit_logs',
            'personal_access_tokens',
            'users',
            'offices',
            'roles',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }

        parent::tearDown();
    }

    public function test_sixth_normalized_login_attempt_is_generically_throttled_without_side_effects(): void
    {
        $user = $this->createUser('Administrator', 'case@example.test');
        $before = $this->businessSnapshot();

        foreach ([
            ' CASE@example.test ',
            'case@EXAMPLE.test',
            ' Case@Example.Test ',
            'CASE@EXAMPLE.TEST',
            'case@example.test',
        ] as $email) {
            $this->postJson('/api/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ]);

        $response->assertStatus(429)->assertExactJson([
            'message' => 'Too many login attempts. Please try again later.',
        ]);
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_malformed_email_is_safe_and_limiter_isolated_by_email_and_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
                ->postJson('/api/login', [
                    'email' => ['not', 'scalar'],
                    'password' => 'wrong-password',
                ])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->postJson('/api/login', [
                'email' => ['still', 'not-scalar'],
                'password' => 'wrong-password',
            ])->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->postJson('/api/login', [
                'email' => ['different', 'ip'],
                'password' => 'wrong-password',
            ])->assertUnprocessable();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->postJson('/api/login', [
                'email' => 'different@example.test',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
    }

    public function test_limiter_recovers_after_decay_window(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'decay@example.test',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'email' => 'decay@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        $this->travel(61)->seconds();

        $this->postJson('/api/login', [
            'email' => 'decay@example.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_successful_login_replaces_only_that_users_token_with_explicit_expiry(): void
    {
        $user = $this->createUser('Administrator', 'admin@example.test');
        $other = $this->createUser('Viewer', 'viewer@example.test');
        $oldToken = $user->createToken('old-session')->plainTextToken;
        $otherToken = $other->createToken('other-session')->plainTextToken;

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ])->assertOk()->assertJsonStructure([
            'message', 'user', 'token', 'token_type',
        ]);

        $this->assertSame(1, $user->tokens()->count());
        $token = $user->tokens()->sole();
        $this->assertSame('doctrack-spa', $token->name);
        $this->assertSame(['*'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(
            now()->addMinutes(479),
            now()->addMinutes(481)
        ));
        $this->assertSame(1, $other->tokens()->count());

        $this->clearTestWebAuthentication();
        $this->withToken($oldToken)->getJson('/api/user')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/user')->assertOk();
        Auth::forgetGuards();
        $this->withToken($otherToken)->getJson('/api/user')->assertOk();

        $this->assertSame(1, AuditLog::count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'module' => AuditLog::MODULE_AUTHENTICATION,
            'action' => AuditLog::ACTION_LOGIN,
            'description' => 'User logged in successfully.',
        ]);
    }

    public function test_locked_password_revalidation_rejects_a_concurrent_password_change_without_touching_its_token(): void
    {
        $user = $this->createUser('Administrator', 'race@example.test');
        $before = $this->businessSnapshot();
        $fixtureApplied = false;

        DB::listen(function ($query) use ($user, &$fixtureApplied): void {
            $normalizedSql = strtolower($query->sql);

            if (
                $fixtureApplied ||
                (!str_contains($normalizedSql, 'from "users"') &&
                    !str_contains($normalizedSql, 'from `users`'))
            ) {
                return;
            }

            $fixtureApplied = true;
            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make('replacement-password'),
            ]);
            $user->createToken('password-change-session');
        });

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ]);

        $response->assertUnauthorized()->assertExactJson([
            'message' => 'Invalid credentials',
        ]);
        $this->assertTrue($fixtureApplied);
        $this->assertTrue(Hash::check(
            'replacement-password',
            $user->fresh()->password
        ));
        $this->assertSame(
            ['password-change-session'],
            $user->tokens()->pluck('name')->all()
        );
        $this->assertSame(0, AuditLog::count());
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertStringNotContainsString(
            'replacement-password',
            $response->getContent()
        );
        $this->assertStringNotContainsString(
            'password-change-session',
            $response->getContent()
        );
    }

    public function test_successful_login_serializes_the_locked_user_snapshot(): void
    {
        $user = $this->createUser('Administrator', 'locked@example.test');
        $fixtureApplied = false;

        DB::listen(function ($query) use ($user, &$fixtureApplied): void {
            $normalizedSql = strtolower($query->sql);

            if (
                $fixtureApplied ||
                (!str_contains($normalizedSql, 'from "users"') &&
                    !str_contains($normalizedSql, 'from `users`'))
            ) {
                return;
            }

            $fixtureApplied = true;
            DB::table('users')->where('id', $user->id)->update([
                'name' => 'Locked User Snapshot',
            ]);
        });

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ])->assertOk()->assertJsonPath(
            'user.name',
            'Locked User Snapshot'
        );

        $this->assertTrue($fixtureApplied);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, AuditLog::count());
        $this->assertIsString($response->json('token'));
    }

    public function test_concurrent_user_deletion_fails_with_generic_invalid_credentials(): void
    {
        $user = $this->createUser('Administrator', 'deleted@example.test');
        $fixtureApplied = false;

        DB::listen(function ($query) use ($user, &$fixtureApplied): void {
            $normalizedSql = strtolower($query->sql);

            if (
                $fixtureApplied ||
                (!str_contains($normalizedSql, 'from "users"') &&
                    !str_contains($normalizedSql, 'from `users`'))
            ) {
                return;
            }

            $fixtureApplied = true;
            DB::table('users')->where('id', $user->id)->delete();
        });

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ]);

        $response->assertUnauthorized()->assertExactJson([
            'message' => 'Invalid credentials',
        ]);
        $this->assertTrue($fixtureApplied);
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(0, AuditLog::count());
        $this->assertStringNotContainsString(
            'deleted',
            strtolower($response->getContent())
        );
    }

    public function test_failed_login_preserves_existing_session(): void
    {
        $user = $this->createUser('Administrator', 'admin@example.test');
        $existingToken = $user->createToken('existing-session')->plainTextToken;

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->assertSame(1, $user->tokens()->count());
        $this->withToken($existingToken)->getJson('/api/user')->assertOk();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_second_successful_login_invalidates_first_bearer_token(): void
    {
        $user = $this->createUser('Administrator', 'admin@example.test');

        $first = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ])->assertOk()->json('token');

        $this->clearTestWebAuthentication();

        $second = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ])->assertOk()->json('token');

        $this->assertSame(1, $user->tokens()->count());
        $this->clearTestWebAuthentication();
        $this->withToken($first)->getJson('/api/user')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($second)->getJson('/api/user')->assertOk();
        $this->assertSame(2, AuditLog::count());
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->createUser('Viewer', 'viewer@example.test');
        $expired = $user->createToken(
            'expired-session',
            ['*'],
            now()->subMinute()
        )->plainTextToken;

        $this->withToken($expired)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_invalid_authentication_configuration_fails_closed(): void
    {
        $user = $this->createUser('Administrator', 'admin@example.test');
        $user->createToken('existing-session');
        config()->set('authentication.token_lifetime_minutes', 'not-valid');

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ])->assertStatus(500)->assertExactJson([
            'message' => 'Authentication is temporarily unavailable.',
        ]);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_security_sensitive_user_changes_revoke_only_target_tokens(): void
    {
        [$administrator, $office] = $this->administratorAndOffice();
        $otherOffice = Office::query()->create([
            'office_name' => 'Other Office',
            'office_code' => 'OTHER',
        ]);
        $otherRole = Role::query()->create(['name' => 'Viewer']);
        $administratorToken = $administrator->createToken('admin-session')->plainTextToken;

        $cases = [
            'email' => ['email' => 'changed@example.test'],
            'password' => [
                'password' => 'new-test-password',
                'password_confirmation' => 'new-test-password',
            ],
            'role' => ['role_id' => $otherRole->id],
            'office' => ['office_id' => $otherOffice->id],
        ];

        foreach ($cases as $case => $overrides) {
            $target = $this->createUser(
                'Office User',
                $case.'@example.test',
                $office
            );
            $target->createToken('target-session');

            $payload = [
                'name' => $target->name,
                'email' => $target->email,
                'role_id' => $target->role_id,
                'office_id' => $target->office_id,
                'password' => null,
                'password_confirmation' => null,
                ...$overrides,
            ];

            $this->withToken($administratorToken)
                ->putJson("/api/users/{$target->id}", $payload)
                ->assertOk();

            $this->assertSame(0, $target->tokens()->count(), $case);
            $this->assertSame(1, $administrator->tokens()->count(), $case);
        }
    }

    public function test_name_only_change_retains_target_tokens(): void
    {
        [$administrator, $office] = $this->administratorAndOffice();
        $target = $this->createUser('Office User', 'target@example.test', $office);
        $target->createToken('target-session');

        Sanctum::actingAs($administrator);
        $this->putJson("/api/users/{$target->id}", [
            'name' => 'Renamed Target',
            'email' => $target->email,
            'role_id' => $target->role_id,
            'office_id' => $target->office_id,
            'password' => null,
            'password_confirmation' => null,
        ])->assertOk();

        $this->assertSame(1, $target->tokens()->count());
    }

    public function test_rejected_user_updates_preserve_target_and_unrelated_tokens(): void
    {
        [$administrator, $office] = $this->administratorAndOffice();
        $target = $this->createUser('Office User', 'target@example.test', $office);
        $target->createToken('target-session');
        $administrator->createToken('admin-session');

        Sanctum::actingAs($administrator);
        $this->putJson("/api/users/{$target->id}", [])
            ->assertUnprocessable();

        $viewer = $this->createUser('Viewer', 'viewer@example.test', $office);
        Sanctum::actingAs($viewer);
        $this->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => 'blocked@example.test',
            'role_id' => $target->role_id,
            'office_id' => $target->office_id,
            'password' => null,
            'password_confirmation' => null,
        ])->assertForbidden();

        $this->assertSame(1, $target->tokens()->count());
        $this->assertSame(1, $administrator->tokens()->count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_self_email_change_completes_then_requires_login_again(): void
    {
        [$administrator, $office] = $this->administratorAndOffice();
        $token = $administrator->createToken('admin-session')->plainTextToken;

        $this->withToken($token)->putJson("/api/users/{$administrator->id}", [
            'name' => $administrator->name,
            'email' => 'new-admin@example.test',
            'role_id' => $administrator->role_id,
            'office_id' => $office->id,
            'password' => null,
            'password_confirmation' => null,
        ])->assertOk();

        $this->assertSame(0, $administrator->tokens()->count());
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->assertSame(1, AuditLog::count());
    }

    private function administratorAndOffice(): array
    {
        $office = Office::query()->create([
            'office_name' => 'Records Office',
            'office_code' => 'REC',
        ]);

        return [
            $this->createUser('Administrator', 'admin@example.test', $office),
            $office,
        ];
    }

    private function createUser(
        string $roleName,
        string $email,
        ?Office $office = null
    ): User {
        $role = Role::query()->firstOrCreate(['name' => $roleName]);

        return User::query()->create([
            'name' => $roleName,
            'email' => $email,
            'password' => Hash::make('test-password'),
            'role_id' => $role->id,
            'office_id' => $office?->id,
            'department_id' => $office?->department_id,
        ]);
    }

    private function businessSnapshot(): array
    {
        return collect([
            'users',
            'documents',
            'document_routes',
            'document_processing_logs',
            'document_qr_codes',
            'document_attachments',
        ])->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->count(),
        ])->all();
    }

    private function clearTestWebAuthentication(): void
    {
        Auth::guard('web')->logout();
        $this->app['session']->flush();
        Auth::forgetGuards();
    }
}
