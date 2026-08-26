<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Process5CAuthUserAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('department_name');
            $table->string('department_code')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('office_name');
            $table->string('office_code')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
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

        Schema::create('personal_access_tokens', function (Blueprint $table) {
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

        $this->createAuditLogsTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('offices');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('roles');

        parent::tearDown();
    }

    public function test_successful_login_creates_exactly_one_safe_audit_row(): void
    {
        $user = $this->createUser('Administrator', 'admin@example.test');

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'user', 'token', 'token_type']);

        $this->assertSame(1, AuditLog::count());
        $audit = AuditLog::sole();
        $this->assertSame(AuditLog::MODULE_AUTHENTICATION, $audit->module);
        $this->assertSame(AuditLog::ACTION_LOGIN, $audit->action);
        $this->assertSame($user->id, $audit->record_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('User logged in successfully.', $audit->description);
        $this->assertStringNotContainsString('test-password', $audit->description);
        $this->assertStringNotContainsString($response->json('token'), $audit->description);
    }

    public function test_invalid_credentials_and_validation_failures_create_no_login_success_audit(): void
    {
        $user = $this->createUser('Administrator', 'admin@example.test');

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->postJson('/api/login', [
            'email' => 'not-an-email',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('audit_logs', [
            'module' => AuditLog::MODULE_AUTHENTICATION,
            'action' => AuditLog::ACTION_LOGIN,
        ]);
    }

    public function test_logout_revokes_current_token_and_creates_exactly_one_audit_row(): void
    {
        $user = $this->createUser('Administrator', 'admin@example.test');
        $token = $user->createToken('auth-token');
        $tokenId = $token->accessToken->id;

        $this->withToken($token->plainTextToken)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out successfully']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertSame(1, AuditLog::count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'module' => AuditLog::MODULE_AUTHENTICATION,
            'action' => AuditLog::ACTION_LOGOUT,
            'record_id' => $user->id,
            'description' => 'User logged out successfully.',
        ]);
    }

    public function test_login_and_logout_succeed_when_audit_persistence_fails(): void
    {
        $user = $this->createUser('Administrator', 'admin@example.test');

        Schema::drop('audit_logs');
        $loginResponse = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ]);
        $loginResponse->assertOk();

        $token = $loginResponse->json('token');
        $tokenId = (int) explode('|', $token, 2)[0];

        auth()->guard()->logout();
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        $this->createAuditLogsTable();
    }

    public function test_administrator_creation_creates_one_expected_audit_row(): void
    {
        [$administrator, $office] = $this->createAdministratorAndOffice();
        $role = Role::query()->create(['name' => 'Office User']);
        Sanctum::actingAs($administrator);

        $response = $this->postJson('/api/users', $this->validUserPayload(
            $role,
            $office,
            'created@example.test'
        ));

        $response->assertCreated();
        $targetId = $response->json('user.id');
        $this->assertSame(1, AuditLog::count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $administrator->id,
            'module' => AuditLog::MODULE_USERS,
            'action' => AuditLog::ACTION_CREATED,
            'record_id' => $targetId,
            'description' => 'Changed fields: name, email, role_id, office_id, department_id; password changed: yes.',
        ]);
    }

    public function test_administrator_update_logs_only_changed_field_names_and_password_flag(): void
    {
        [$administrator, $office] = $this->createAdministratorAndOffice();
        $target = $this->createUser('Office User', 'target@example.test', $office);
        $newPassword = 'new-secret-password';
        Sanctum::actingAs($administrator);

        $response = $this->putJson("/api/users/{$target->id}", [
            'name' => 'Updated Target',
            'email' => $target->email,
            'role_id' => $target->role_id,
            'office_id' => $office->id,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response->assertOk();
        $this->assertSame(1, AuditLog::count());
        $audit = AuditLog::sole();
        $this->assertSame(AuditLog::MODULE_USERS, $audit->module);
        $this->assertSame(AuditLog::ACTION_UPDATED, $audit->action);
        $this->assertSame($target->id, $audit->record_id);
        $this->assertSame($administrator->id, $audit->user_id);
        $this->assertSame(
            'Changed fields: name; password changed: yes.',
            $audit->description
        );
        $this->assertStringNotContainsString($newPassword, $audit->description);
        $this->assertStringNotContainsString(
            User::query()->findOrFail($target->id)->password,
            $audit->description
        );
        $this->assertStringNotContainsString('password_confirmation', $audit->description);
    }

    public function test_no_op_update_is_preserved_and_audited_without_field_values(): void
    {
        [$administrator, $office] = $this->createAdministratorAndOffice();
        $target = $this->createUser('Office User', 'target@example.test', $office);
        Sanctum::actingAs($administrator);

        $this->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $target->role_id,
            'office_id' => $target->office_id,
            'password' => null,
            'password_confirmation' => null,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_USERS,
            'action' => AuditLog::ACTION_UPDATED,
            'record_id' => $target->id,
            'description' => 'Changed fields: none; password changed: no.',
        ]);
    }

    public function test_invalid_create_update_and_rejected_self_role_change_create_no_success_rows(): void
    {
        [$administrator, $office] = $this->createAdministratorAndOffice();
        $officeUserRole = Role::query()->create(['name' => 'Office User']);
        $target = $this->createUser('Office User', 'target@example.test', $office);
        Sanctum::actingAs($administrator);

        $this->postJson('/api/users', [])->assertUnprocessable();
        $this->putJson("/api/users/{$target->id}", [])->assertUnprocessable();

        $this->putJson("/api/users/{$administrator->id}", [
            'name' => $administrator->name,
            'email' => $administrator->email,
            'role_id' => $officeUserRole->id,
            'office_id' => $office->id,
            'password' => null,
            'password_confirmation' => null,
        ])->assertUnprocessable();

        $this->assertSame(0, AuditLog::count());
        $this->assertSame('Administrator', $administrator->fresh()->role->name);
    }

    public function test_unauthenticated_and_non_administrator_roles_cannot_mutate_users_or_audit_success(): void
    {
        [, $office] = $this->createAdministratorAndOffice();
        $targetRole = Role::query()->create(['name' => 'Target Role']);
        $target = $this->createUser('Office User', 'target@example.test', $office);
        $payload = $this->validUserPayload($targetRole, $office, 'blocked@example.test');
        $initialUserCount = User::count();

        $this->postJson('/api/users', $payload)->assertUnauthorized();
        $this->putJson("/api/users/{$target->id}", [
            ...$payload,
            'email' => $target->email,
        ])->assertUnauthorized();

        foreach (['Records Officer', 'Office User', 'Viewer'] as $roleName) {
            Sanctum::actingAs($this->createUser(
                $roleName,
                strtolower(str_replace(' ', '.', $roleName)).'@example.test',
                $office
            ));

            $this->postJson('/api/users', $payload)->assertForbidden();
            $this->putJson("/api/users/{$target->id}", [
                ...$payload,
                'email' => $target->email,
            ])->assertForbidden();
        }

        $this->assertSame($initialUserCount + 3, User::count());
        $this->assertSame('Office User', $target->fresh()->name);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_user_creation_and_update_succeed_when_audit_persistence_fails(): void
    {
        [$administrator, $office] = $this->createAdministratorAndOffice();
        $role = Role::query()->create(['name' => 'Office User']);
        Sanctum::actingAs($administrator);

        Schema::drop('audit_logs');
        $createResponse = $this->postJson('/api/users', $this->validUserPayload(
            $role,
            $office,
            'survives@example.test'
        ));
        $createResponse->assertCreated();

        $targetId = $createResponse->json('user.id');
        $this->putJson("/api/users/{$targetId}", [
            'name' => 'Audit Failure Survivor',
            'email' => 'survives@example.test',
            'role_id' => $role->id,
            'office_id' => $office->id,
            'password' => null,
            'password_confirmation' => null,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $targetId,
            'name' => 'Audit Failure Survivor',
        ]);

        $this->createAuditLogsTable();
    }

    private function createAdministratorAndOffice(): array
    {
        $department = Department::query()->create([
            'department_name' => 'Administration',
            'department_code' => 'ADMIN',
        ]);
        $office = Office::query()->create([
            'department_id' => $department->id,
            'office_name' => 'Administrator Office',
            'office_code' => 'AO',
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
            'department_id' => $office?->department_id,
            'office_id' => $office?->id,
        ]);
    }

    private function validUserPayload(
        Role $role,
        Office $office,
        string $email
    ): array {
        return [
            'name' => 'Created User',
            'email' => $email,
            'role_id' => $role->id,
            'office_id' => $office->id,
            'password' => 'created-password',
            'password_confirmation' => 'created-password',
        ];
    }

    private function createAuditLogsTable(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module', 100);
            $table->string('action', 100);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });
    }
}
