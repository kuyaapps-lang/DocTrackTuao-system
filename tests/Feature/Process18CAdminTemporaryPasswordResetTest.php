<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Process18CAdminTemporaryPasswordResetTest extends TestCase
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

        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('department_name');
            $table->string('department_code')->unique();
            $table->timestamps();
        });

        Schema::create('offices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('office_name');
            $table->string('office_code')->unique();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
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

        config()->set('authentication.login_max_attempts', 5);
        config()->set('authentication.login_decay_seconds', 60);
        config()->set('authentication.token_lifetime_minutes', 480);
        config()->set('authentication.token_name', 'doctrack-spa');
        config()->set('sanctum.expiration', 480);
    }

    protected function tearDown(): void
    {
        $this->clearAuthenticationState();

        foreach ([
            'audit_logs',
            'personal_access_tokens',
            'users',
            'offices',
            'departments',
            'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_admin_reset_sets_temporary_password_flag_revokes_tokens_and_audits_safely(): void
    {
        [$admin, $target] = $this->adminAndTarget();
        $target->createToken('target-session');
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/users/{$target->id}/reset-password", [
            'password' => 'temporary-secret-18c',
            'password_confirmation' => 'temporary-secret-18c',
        ])->assertOk()
            ->assertJsonPath('message', 'Temporary password set successfully.')
            ->assertJsonPath('user.must_change_password', true);

        $fresh = $target->fresh();
        $this->assertTrue($fresh->must_change_password);
        $this->assertTrue(Hash::check('temporary-secret-18c', $fresh->password));
        $this->assertSame(0, $fresh->tokens()->count());

        $audit = AuditLog::sole();
        $this->assertSame(AuditLog::MODULE_USERS, $audit->module);
        $this->assertSame(AuditLog::ACTION_PASSWORD_RESET, $audit->action);
        $this->assertSame($target->id, $audit->record_id);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertStringNotContainsString('temporary-secret-18c', $audit->description);
        $this->assertStringNotContainsString($fresh->password, $audit->description);
        $this->assertStringNotContainsString('password_confirmation', $response->getContent());
    }

    public function test_non_admin_self_reset_and_only_admin_self_lockout_are_blocked(): void
    {
        [$admin, $target] = $this->adminAndTarget();
        Sanctum::actingAs($this->createUser('Office User', 'office@example.test'));

        $this->postJson("/api/users/{$target->id}/reset-password", [
            'password' => 'temporary-secret-18c',
            'password_confirmation' => 'temporary-secret-18c',
        ])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->postJson("/api/users/{$admin->id}/reset-password", [
            'password' => 'self-secret-18c',
            'password_confirmation' => 'self-secret-18c',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Administrators cannot issue a temporary password for their own account.'
            );

        $this->assertFalse($admin->fresh()->must_change_password);
        $this->assertFalse($target->fresh()->must_change_password);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_temp_password_login_is_restricted_until_password_is_changed(): void
    {
        [$admin, $target] = $this->adminAndTarget('Administrator');
        $this->resetTargetPassword($admin, $target, 'temporary-secret-18c');

        $login = $this->postJson('/api/login', [
            'email' => $target->email,
            'password' => 'temporary-secret-18c',
        ])->assertOk()
            ->assertJsonPath('user.must_change_password', true);

        $token = $login->json('token');
        $this->clearAuthenticationState();

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('must_change_password', true);

        $this->withToken($token)
            ->getJson('/api/users')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'You must change your password before continuing.'
            );

        $this->withToken($token)
            ->postJson('/api/me/password', [
                'current_password' => 'temporary-secret-18c',
                'password' => 'new-user-secret-18c',
                'password_confirmation' => 'new-user-secret-18c',
            ])->assertOk()
            ->assertJsonPath(
                'message',
                'Password changed successfully. Please log in again.'
            );

        $fresh = $target->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check('new-user-secret-18c', $fresh->password));
        $this->assertSame(0, $fresh->tokens()->count());

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();

        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_USERS,
            'action' => AuditLog::ACTION_PASSWORD_RESET,
            'record_id' => $target->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_AUTHENTICATION,
            'action' => AuditLog::ACTION_LOGIN,
            'record_id' => $target->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_AUTHENTICATION,
            'action' => AuditLog::ACTION_PASSWORD_CHANGED,
            'record_id' => $target->id,
            'user_id' => $target->id,
        ]);

        $descriptions = AuditLog::query()->pluck('description')->implode("\n");
        $this->assertStringNotContainsString('temporary-secret-18c', $descriptions);
        $this->assertStringNotContainsString('new-user-secret-18c', $descriptions);
    }

    public function test_must_change_user_can_logout(): void
    {
        [$admin, $target] = $this->adminAndTarget();
        $this->resetTargetPassword($admin, $target, 'temporary-secret-18c');
        $token = $this->postJson('/api/login', [
            'email' => $target->email,
            'password' => 'temporary-secret-18c',
        ])->assertOk()->json('token');
        $this->clearAuthenticationState();

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertSame(0, $target->fresh()->tokens()->count());
        $this->assertTrue($target->fresh()->must_change_password);
        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_AUTHENTICATION,
            'action' => AuditLog::ACTION_LOGOUT,
            'record_id' => $target->id,
        ]);
    }

    public function test_password_change_validates_current_password_confirmation_and_same_password(): void
    {
        [$admin, $target] = $this->adminAndTarget();
        $this->resetTargetPassword($admin, $target, 'temporary-secret-18c');
        $token = $this->postJson('/api/login', [
            'email' => $target->email,
            'password' => 'temporary-secret-18c',
        ])->assertOk()->json('token');
        $this->clearAuthenticationState();

        $this->withToken($token)
            ->postJson('/api/me/password', [
                'current_password' => 'wrong-secret',
                'password' => 'new-user-secret-18c',
                'password_confirmation' => 'new-user-secret-18c',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->withToken($token)
            ->postJson('/api/me/password', [
                'current_password' => 'temporary-secret-18c',
                'password' => 'new-user-secret-18c',
                'password_confirmation' => 'different-secret-18c',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->withToken($token)
            ->postJson('/api/me/password', [
                'current_password' => 'temporary-secret-18c',
                'password' => 'temporary-secret-18c',
                'password_confirmation' => 'temporary-secret-18c',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $fresh = $target->fresh();
        $this->assertTrue($fresh->must_change_password);
        $this->assertTrue(Hash::check('temporary-secret-18c', $fresh->password));
    }

    private function adminAndTarget(string $targetRole = 'Office User'): array
    {
        return [
            $this->createUser('Administrator', 'admin@example.test'),
            $this->createUser($targetRole, 'target@example.test'),
        ];
    }

    private function resetTargetPassword(
        User $admin,
        User $target,
        string $password
    ): void {
        $token = $admin->createToken('admin-reset-session')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/users/{$target->id}/reset-password", [
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertOk();

        $this->clearAuthenticationState();
    }

    private function clearAuthenticationState(): void
    {
        Auth::guard('web')->logout();
        $this->app['session']->flush();
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }

    private function createUser(string $roleName, string $email): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName]);
        $office = Office::query()->firstOrCreate(
            ['office_code' => 'REC'],
            ['office_name' => 'Records Office']
        );

        return User::query()->create([
            'name' => $roleName.' User',
            'email' => $email,
            'password' => Hash::make('original-secret-18c'),
            'role_id' => $role->id,
            'office_id' => $office->id,
        ]);
    }
}
