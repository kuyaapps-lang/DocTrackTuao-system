<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Office;
use App\Models\PasswordResetRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Process20BPasswordResetRequestTest extends TestCase
{
    private const PUBLIC_MESSAGE =
        'If the account exists, an administrator will review the password reset request.';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('offices', function (Blueprint $table): void {
            $table->id();
            $table->string('office_name');
            $table->string('office_code')->unique();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->rememberToken();
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

        Schema::create('password_reset_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email');
            $table->string('name')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('requested_ip', 45)->nullable();
            $table->string('requested_user_agent')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
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
    }

    protected function tearDown(): void
    {
        $this->clearAuthenticationState();

        foreach ([
            'audit_logs',
            'password_reset_requests',
            'personal_access_tokens',
            'users',
            'offices',
            'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_matched_and_unmatched_public_requests_return_same_generic_response(): void
    {
        $this->user('Office User', 'matched@example.test');
        $this->clearLimiter('matched@example.test');
        $this->clearLimiter('missing@example.test');

        $matched = $this->postJson('/api/password-reset-requests', [
            'email' => ' MATCHED@example.test ',
            'name' => ' Matched User ',
            'message' => ' Please reset my password. ',
        ])->assertAccepted();

        $unmatched = $this->postJson('/api/password-reset-requests', [
            'email' => 'missing@example.test',
            'name' => 'Missing User',
            'message' => 'Need access.',
        ])->assertAccepted();

        $this->assertSame($matched->json(), $unmatched->json());
        $this->assertSame(self::PUBLIC_MESSAGE, $matched->json('message'));

        $matchedRow = PasswordResetRequest::query()
            ->where('email', 'matched@example.test')
            ->sole();
        $unmatchedRow = PasswordResetRequest::query()
            ->where('email', 'missing@example.test')
            ->sole();

        $this->assertNotNull($matchedRow->user_id);
        $this->assertNull($unmatchedRow->user_id);
        $this->assertSame(PasswordResetRequest::STATUS_PENDING, $matchedRow->status);
        $this->assertSame(PasswordResetRequest::STATUS_PENDING, $unmatchedRow->status);

        $this->assertSame(2, AuditLog::query()
            ->where('module', AuditLog::MODULE_PASSWORD_RESET_REQUESTS)
            ->where('action', AuditLog::ACTION_PASSWORD_RESET_REQUESTED)
            ->count());
    }

    public function test_duplicate_pending_request_coalesces_safely(): void
    {
        $this->user('Office User', 'duplicate@example.test');
        $this->clearLimiter('duplicate@example.test');

        $this->postJson('/api/password-reset-requests', [
            'email' => 'duplicate@example.test',
            'name' => 'First Name',
            'message' => 'First message',
        ])->assertAccepted();

        $this->postJson('/api/password-reset-requests', [
            'email' => 'DUPLICATE@example.test',
            'name' => 'Second Name',
            'message' => 'Second message',
        ])->assertAccepted();

        $request = PasswordResetRequest::sole();

        $this->assertSame('duplicate@example.test', $request->email);
        $this->assertSame('Second Name', $request->name);
        $this->assertSame('Second message', $request->message);
        $this->assertSame(PasswordResetRequest::STATUS_PENDING, $request->status);
    }

    public function test_admin_can_list_pending_and_non_admin_is_forbidden(): void
    {
        $target = $this->user('Office User', 'target@example.test');
        $pending = PasswordResetRequest::query()->create([
            'user_id' => $target->id,
            'email' => $target->email,
            'status' => PasswordResetRequest::STATUS_PENDING,
        ]);
        PasswordResetRequest::query()->create([
            'email' => 'closed@example.test',
            'status' => PasswordResetRequest::STATUS_REJECTED,
        ]);

        Sanctum::actingAs($this->user('Administrator', 'admin@example.test'));
        $response = $this->getJson('/api/password-reset-requests?status=pending')
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame($pending->id, $response->json('data.0.id'));
        $this->assertSame($target->email, $response->json('data.0.user.email'));

        Sanctum::actingAs($this->user('Office User', 'ordinary@example.test'));
        $this->getJson('/api/password-reset-requests?status=pending')
            ->assertForbidden();
    }

    public function test_resolve_sets_temporary_password_forces_change_revokes_tokens_and_audits_safely(): void
    {
        $admin = $this->user('Administrator', 'admin@example.test');
        $target = $this->user('Office User', 'target@example.test');
        $target->createToken('existing-session');
        $resetRequest = PasswordResetRequest::query()->create([
            'user_id' => $target->id,
            'email' => $target->email,
            'status' => PasswordResetRequest::STATUS_PENDING,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->postJson(
            "/api/password-reset-requests/{$resetRequest->id}/resolve",
            [
                'password' => 'temporary-secret-20b',
                'password_confirmation' => 'temporary-secret-20b',
                'resolution_note' => 'Handled in person.',
            ]
        )->assertOk()
            ->assertJsonPath('message', 'Password reset request resolved successfully.')
            ->assertJsonPath('password_reset_request.status', PasswordResetRequest::STATUS_RESOLVED);

        $freshTarget = $target->fresh();
        $freshRequest = $resetRequest->fresh();

        $this->assertTrue(Hash::check('temporary-secret-20b', $freshTarget->password));
        $this->assertTrue($freshTarget->must_change_password);
        $this->assertSame(0, $freshTarget->tokens()->count());
        $this->assertSame(PasswordResetRequest::STATUS_RESOLVED, $freshRequest->status);
        $this->assertSame($admin->id, $freshRequest->resolved_by_user_id);
        $this->assertNotNull($freshRequest->resolved_at);

        $audit = AuditLog::query()
            ->where('action', AuditLog::ACTION_PASSWORD_RESET_REQUEST_RESOLVED)
            ->sole();
        $this->assertSame(AuditLog::MODULE_PASSWORD_RESET_REQUESTS, $audit->module);
        $this->assertSame($resetRequest->id, $audit->record_id);
        $this->assertSame($admin->id, $audit->user_id);

        $this->assertNoSensitiveLeak($response->getContent());
        $this->assertNoSensitiveLeak(AuditLog::query()->pluck('description')->implode("\n"));
    }

    public function test_reject_marks_request_rejected_and_audits(): void
    {
        $admin = $this->user('Administrator', 'admin@example.test');
        $resetRequest = PasswordResetRequest::query()->create([
            'email' => 'unknown@example.test',
            'status' => PasswordResetRequest::STATUS_PENDING,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->postJson(
            "/api/password-reset-requests/{$resetRequest->id}/reject",
            ['resolution_note' => 'Could not verify requester.']
        )->assertOk()
            ->assertJsonPath('message', 'Password reset request rejected.')
            ->assertJsonPath('password_reset_request.status', PasswordResetRequest::STATUS_REJECTED);

        $freshRequest = $resetRequest->fresh();

        $this->assertSame(PasswordResetRequest::STATUS_REJECTED, $freshRequest->status);
        $this->assertSame($admin->id, $freshRequest->resolved_by_user_id);
        $this->assertNotNull($freshRequest->resolved_at);

        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_PASSWORD_RESET_REQUESTS,
            'action' => AuditLog::ACTION_PASSWORD_RESET_REQUEST_REJECTED,
            'record_id' => $resetRequest->id,
            'user_id' => $admin->id,
        ]);

        $this->assertNoSensitiveLeak($response->getContent());
    }

    public function test_unmatched_request_cannot_be_resolved_until_linked_to_a_user(): void
    {
        $resetRequest = PasswordResetRequest::query()->create([
            'email' => 'unknown@example.test',
            'status' => PasswordResetRequest::STATUS_PENDING,
        ]);

        Sanctum::actingAs($this->user('Administrator', 'admin@example.test'));

        $this->postJson(
            "/api/password-reset-requests/{$resetRequest->id}/resolve",
            [
                'password' => 'temporary-secret-20b',
                'password_confirmation' => 'temporary-secret-20b',
            ]
        )->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'This password reset request is not linked to an active user.'
            );

        $this->assertSame(PasswordResetRequest::STATUS_PENDING, $resetRequest->fresh()->status);
    }

    private function user(string $roleName, string $email): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName]);
        $office = Office::query()->firstOrCreate(
            ['office_code' => 'REC'],
            ['office_name' => 'Records Office']
        );

        return User::query()->create([
            'name' => $roleName.' User',
            'email' => $email,
            'password' => Hash::make('original-secret-20b'),
            'role_id' => $role->id,
            'office_id' => $office->id,
        ]);
    }

    private function clearLimiter(string $email): void
    {
        RateLimiter::clear('password-reset-request:'.hash(
            'sha256',
            mb_strtolower(trim($email))."\0".'127.0.0.1'
        ));
    }

    private function assertNoSensitiveLeak(string $content): void
    {
        foreach ([
            'temporary-secret-20b',
            'password_confirmation',
            'tokenable',
            '$2y$',
            'hash',
        ] as $marker) {
            $this->assertStringNotContainsString($marker, $content);
        }
    }

    private function clearAuthenticationState(): void
    {
        Auth::guard('web')->logout();
        $this->app['session']->flush();
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }
}
