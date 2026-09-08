<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

    public function test_unauthenticated_logout_creates_no_logout_audit(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();

        $this->assertDatabaseMissing('audit_logs', [
            'module' => AuditLog::MODULE_AUTHENTICATION,
            'action' => AuditLog::ACTION_LOGOUT,
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

    public function test_user_management_reads_and_mutations_are_administrator_only(): void
    {
        [$administrator, $office] = $this->createAdministratorAndOffice();
        $role = Role::query()->create(['name' => 'Viewer']);
        $target = $this->createUser('Office User', 'target-access@example.test', $office);
        $payload = $this->validUserPayload($role, $office, 'new-access@example.test');

        foreach ([
            ['GET', '/api/users', null],
            ['GET', '/api/users/form-options', null],
            ['POST', '/api/users', $payload],
            ['PUT', "/api/users/{$target->id}", [...$payload, 'email' => $target->email]],
        ] as [$method, $uri, $body]) {
            $before = $this->securitySnapshot();
            $this->json($method, $uri, $body ?? [])->assertUnauthorized();
            $this->assertSame($before, $this->securitySnapshot());
        }

        foreach (['Records Officer', 'Office User', 'Viewer'] as $roleName) {
            Sanctum::actingAs($this->createUser(
                $roleName,
                str_replace(' ', '-', strtolower($roleName)).'-access@example.test',
                $office
            ));
            foreach ([
                ['GET', '/api/users', null],
                ['GET', '/api/users/form-options', null],
                ['POST', '/api/users', $payload],
                ['PUT', "/api/users/{$target->id}", [...$payload, 'email' => $target->email]],
            ] as [$method, $uri, $body]) {
                $before = $this->securitySnapshot();
                $this->json($method, $uri, $body ?? [])->assertForbidden();
                $this->assertSame($before, $this->securitySnapshot());
            }
        }

        Sanctum::actingAs($administrator);
        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/users/form-options')->assertOk();
    }

    public function test_each_supported_database_role_is_assignable_without_hard_coded_ids(): void
    {
        [$administrator, $office] = $this->createAdministratorAndOffice();
        Sanctum::actingAs($administrator);

        foreach (['Administrator', 'Records Officer', 'Office User', 'Viewer'] as $index => $name) {
            $role = Role::query()->firstOrCreate(['name' => $name]);
            $response = $this->postJson('/api/users', $this->validUserPayload(
                $role,
                $office,
                "supported-{$index}@example.test"
            ))->assertCreated();

            $this->assertSame($role->id, $response->json('user.role_id'));
            $this->assertSame($name, $response->json('user.role.name'));
            $this->assertDatabaseHas('users', [
                'id' => $response->json('user.id'),
                'role_id' => $role->id,
                'office_id' => $office->id,
                'department_id' => $office->department_id,
            ]);
        }
    }

    public function test_existing_unsupported_roles_are_rejected_on_create_and_update(): void
    {
        [$administrator, $office] = $this->createAdministratorAndOffice();
        $target = $this->createUser('Office User', 'unsupported-target@example.test', $office);
        $target->createToken('target-session');
        $administrator->createToken('administrator-session');
        Sanctum::actingAs($administrator);

        foreach (['Unexpected Role', 'administrator'] as $index => $name) {
            $unsupported = Role::query()->create(['name' => $name]);
            $before = $this->securitySnapshot();
            $this->postJson('/api/users', $this->validUserPayload(
                $unsupported,
                $office,
                "unsupported-{$index}@example.test"
            ))->assertUnprocessable()->assertJsonValidationErrors('role_id');
            $this->assertSame($before, $this->securitySnapshot());

            $before = $this->securitySnapshot();
            $this->putJson("/api/users/{$target->id}", [
                'name' => $target->name,
                'email' => $target->email,
                'role_id' => $unsupported->id,
                'office_id' => $office->id,
                'password' => null,
                'password_confirmation' => null,
            ])->assertUnprocessable()->assertJsonValidationErrors('role_id');
            $this->assertSame($before, $this->securitySnapshot());
        }
    }

    public function test_role_office_and_unknown_field_validation_is_inert(): void
    {
        [$administrator, $office] = $this->createAdministratorAndOffice();
        $role = Role::query()->create(['name' => 'Viewer']);
        $target = $this->createUser('Office User', 'validation-target@example.test', $office);
        $target->createToken('target-session');
        Sanctum::actingAs($administrator);

        foreach ([
            ['role_id' => 999999],
            ['role_id' => 'invalid'],
            ['role_id' => null],
            ['office_id' => 999999],
            ['office_id' => 'invalid'],
            ['office_id' => null],
            ['permissions' => ['*']],
            ['token' => 'crafted-token'],
            ['remember_token' => 'crafted-remember-token'],
            ['created_at' => '2000-01-01 00:00:00'],
            ['is_active' => false],
            ['status' => 'disabled'],
        ] as $index => $override) {
            $payload = [
                'name' => $target->name,
                'email' => $target->email,
                'role_id' => $role->id,
                'office_id' => $office->id,
                'password' => null,
                'password_confirmation' => null,
                ...$override,
            ];
            $before = $this->securitySnapshot();
            $this->putJson("/api/users/{$target->id}", $payload)
                ->assertUnprocessable();
            $this->assertSame($before, $this->securitySnapshot(), "validation case {$index}");
        }
    }

    public function test_user_and_form_option_responses_are_exact_safe_allowlists(): void
    {
        [$administrator, $office] = $this->createAdministratorAndOffice();
        $supported = [];
        foreach (['Records Officer', 'Office User', 'Viewer'] as $name) {
            $supported[$name] = Role::query()->create([
                'name' => $name,
                'description' => 'Internal role description',
            ]);
        }
        Role::query()->create(['name' => 'Unsupported', 'description' => 'Must not be assignable']);
        Sanctum::actingAs($administrator);

        $before = $this->securitySnapshot();
        $options = $this->getJson('/api/users/form-options')->assertOk();
        $this->assertSame($before, $this->securitySnapshot());
        $this->assertSame(['roles', 'offices'], array_keys($options->json()));
        $this->assertSame(
            ['Administrator', 'Office User', 'Records Officer', 'Viewer'],
            array_column($options->json('roles'), 'name')
        );
        foreach ($options->json('roles') as $item) {
            $this->assertSame(['id', 'name'], array_keys($item));
        }
        foreach ($options->json('offices') as $item) {
            $this->assertSame(['id', 'office_name', 'office_code', 'department_id'], array_keys($item));
        }

        $before = $this->securitySnapshot();
        $list = $this->getJson('/api/users')->assertOk();
        $this->assertSame($before, $this->securitySnapshot());
        foreach ($list->json() as $item) {
            $this->assertSafeUserShape($item);
        }
        $serialized = strtolower($list->getContent().$options->getContent());
        foreach (['password', 'remember_token', 'personal_access', 'token', 'abilities', 'description', 'created_at', 'updated_at'] as $forbidden) {
            $this->assertFalse(str_contains($serialized, $forbidden), 'User-management read response leaked a forbidden field.');
        }

        $created = $this->postJson('/api/users', $this->validUserPayload(
            $supported['Viewer'],
            $office,
            'safe-response@example.test'
        ))->assertCreated();
        $this->assertSame(['message', 'user'], array_keys($created->json()));
        $this->assertSafeUserShape($created->json('user'));

        $updated = $this->putJson('/api/users/'.$created->json('user.id'), [
            'name' => 'Updated Safe Response',
            'email' => 'safe-response@example.test',
            'role_id' => $supported['Viewer']->id,
            'office_id' => $office->id,
            'password' => null,
            'password_confirmation' => null,
        ])->assertOk();
        $this->assertSame(['message', 'user'], array_keys($updated->json()));
        $this->assertSafeUserShape($updated->json('user'));
    }

    public function test_self_role_guard_protects_the_sole_administrator_and_preserves_state(): void
    {
        [$administrator, $office] = $this->createAdministratorAndOffice();
        $viewer = Role::query()->create(['name' => 'Viewer']);
        $administrator->createToken('sole-administrator-session');
        Sanctum::actingAs($administrator);
        $before = $this->securitySnapshot();

        $this->putJson("/api/users/{$administrator->id}", [
            'name' => $administrator->name,
            'email' => $administrator->email,
            'role_id' => $viewer->id,
            'office_id' => $office->id,
            'password' => null,
            'password_confirmation' => null,
        ])->assertUnprocessable()->assertExactJson([
            'message' => 'You cannot change your own role.',
        ]);

        $this->assertSame($before, $this->securitySnapshot());
        $this->assertSame(1, User::query()->where('role_id', $administrator->role_id)->count());
    }

    private function assertSafeUserShape(array $user): void
    {
        $this->assertSame([
            'id', 'name', 'email', 'role_id', 'department_id', 'office_id', 'role', 'office',
        ], array_keys($user));
        if ($user['role'] !== null) {
            $this->assertSame(['id', 'name'], array_keys($user['role']));
        }
        if ($user['office'] !== null) {
            $this->assertSame(
                ['id', 'office_name', 'office_code', 'department_id'],
                array_keys($user['office'])
            );
        }
    }

    private function securitySnapshot(): array
    {
        return [
            'users' => DB::table('users')->orderBy('id')->get([
                'id', 'name', 'email', 'role_id', 'department_id', 'office_id',
                'email_verified_at', 'created_at', 'updated_at',
            ])->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'email_sha256' => hash('sha256', strtolower((string) $row->email)),
                'role_id' => $row->role_id === null ? null : (int) $row->role_id,
                'department_id' => $row->department_id === null ? null : (int) $row->department_id,
                'office_id' => $row->office_id === null ? null : (int) $row->office_id,
                'email_verified_at' => $row->email_verified_at === null ? null : (string) $row->email_verified_at,
                'created_at' => $row->created_at === null ? null : (string) $row->created_at,
                'updated_at' => $row->updated_at === null ? null : (string) $row->updated_at,
            ])->all(),
            'roles' => $this->normalizedRows('roles', ['id', 'name', 'description', 'created_at', 'updated_at']),
            'departments' => $this->normalizedRows('departments', ['id', 'department_name', 'department_code', 'description', 'created_at', 'updated_at']),
            'offices' => $this->normalizedRows('offices', ['id', 'department_id', 'office_name', 'office_code', 'description', 'created_at', 'updated_at']),
            'tokens' => DB::table('personal_access_tokens')->orderBy('id')->get([
                'id', 'tokenable_type', 'tokenable_id', 'name', 'abilities',
                'last_used_at', 'expires_at', 'created_at', 'updated_at',
            ])->map(fn ($row): array => [
                'id' => (int) $row->id,
                'tokenable_type' => (string) $row->tokenable_type,
                'tokenable_id' => (int) $row->tokenable_id,
                'name' => (string) $row->name,
                'abilities' => $row->abilities === null ? null : json_decode((string) $row->abilities, true),
                'last_used_at' => $row->last_used_at === null ? null : (string) $row->last_used_at,
                'expires_at' => $row->expires_at === null ? null : (string) $row->expires_at,
                'created_at' => $row->created_at === null ? null : (string) $row->created_at,
                'updated_at' => $row->updated_at === null ? null : (string) $row->updated_at,
            ])->all(),
            'audits' => $this->normalizedRows('audit_logs', [
                'id', 'user_id', 'module', 'action', 'record_id', 'description',
                'ip_address', 'user_agent', 'created_at', 'updated_at',
            ]),
        ];
    }

    private function normalizedRows(string $table, array $columns): array
    {
        return DB::table($table)->orderBy('id')->get($columns)
            ->map(fn ($row): array => array_combine(
                $columns,
                array_map(fn (string $column) => $row->{$column}, $columns)
            ))->all();
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
