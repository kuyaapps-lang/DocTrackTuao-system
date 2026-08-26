<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Process5EAuditLogApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module', 100);
            $table->string('action', 100);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');

        parent::tearDown();
    }

    public function test_administrator_receives_bounded_safe_newest_first_results(): void
    {
        $administrator = $this->user('Administrator', 1, 'admin@example.test');
        $actor = $this->user('Office User', 2, 'actor@example.test');

        foreach (range(1, 30) as $recordId) {
            $this->audit($actor, $recordId);
        }

        Sanctum::actingAs($administrator);
        $response = $this->getJson('/api/audit-logs')->assertOk();

        $response
            ->assertJsonPath('per_page', 25)
            ->assertJsonPath('total', 30)
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0.record_id', 30)
            ->assertJsonPath('data.24.record_id', 6)
            ->assertJsonPath('data.0.actor.id', $actor->id)
            ->assertJsonPath('data.0.actor.name', $actor->name)
            ->assertJsonStructure([
                'current_page',
                'data',
                'first_page_url',
                'from',
                'last_page',
                'last_page_url',
                'links',
                'next_page_url',
                'path',
                'per_page',
                'prev_page_url',
                'to',
                'total',
            ]);

        foreach ($response->json('data') as $item) {
            $this->assertSame(
                ['id', 'actor', 'module', 'action', 'record_id', 'description', 'ip_address', 'created_at'],
                array_keys($item)
            );
            $this->assertSame(['id', 'name'], array_keys($item['actor']));
        }

        foreach ($this->prohibitedMarkers($actor) as $marker) {
            $this->assertStringNotContainsString($marker, $response->getContent());
        }
    }

    public function test_records_officer_receives_system_wide_results(): void
    {
        $recordsOfficer = $this->user('Records Officer', 10, 'records@example.test');
        $otherOfficeActor = $this->user('Office User', 99, 'other@example.test');
        $audit = $this->audit($otherOfficeActor, 77);
        Sanctum::actingAs($recordsOfficer);

        $this->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.id', $audit->id)
            ->assertJsonPath('data.0.actor.id', $otherOfficeActor->id);
    }

    public function test_office_user_and_viewer_are_forbidden(): void
    {
        foreach (['Office User', 'Viewer'] as $index => $roleName) {
            Sanctum::actingAs($this->user(
                $roleName,
                1,
                "blocked{$index}@example.test"
            ));

            $this->getJson('/api/audit-logs')->assertForbidden();
        }
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/audit-logs')->assertUnauthorized();
    }

    public function test_allowed_page_sizes_are_bounded(): void
    {
        $administrator = $this->user('Administrator', 1, 'admin@example.test');
        foreach (range(1, 55) as $recordId) {
            $this->audit($administrator, $recordId);
        }
        Sanctum::actingAs($administrator);

        foreach ([10, 25, 50] as $perPage) {
            $this->getJson("/api/audit-logs?per_page={$perPage}")
                ->assertOk()
                ->assertJsonPath('per_page', $perPage)
                ->assertJsonCount($perPage, 'data');
        }
    }

    public function test_invalid_pagination_and_filters_are_rejected(): void
    {
        Sanctum::actingAs($this->user('Administrator', 1, 'admin@example.test'));

        foreach ([
            'page=0',
            'page=not-a-number',
            'per_page=1',
            'per_page=51',
            'per_page=all',
            'module=invalid',
            'action=invalid',
        ] as $query) {
            $this->getJson("/api/audit-logs?{$query}")->assertUnprocessable();
        }
    }

    public function test_exact_module_and_action_filters_work(): void
    {
        $administrator = $this->user('Administrator', 1, 'admin@example.test');
        $this->audit($administrator, 1, AuditLog::MODULE_USERS, AuditLog::ACTION_CREATED);
        $matching = $this->audit(
            $administrator,
            2,
            AuditLog::MODULE_ATTACHMENTS,
            AuditLog::ACTION_UPLOADED
        );
        $this->audit(
            $administrator,
            3,
            AuditLog::MODULE_ATTACHMENTS,
            AuditLog::ACTION_DELETED
        );
        Sanctum::actingAs($administrator);

        $this->getJson(
            '/api/audit-logs?module=attachments&action=uploaded'
        )->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_deleted_actor_is_serialized_as_null(): void
    {
        $administrator = $this->user('Administrator', 1, 'admin@example.test');
        AuditLog::create([
            'user_id' => null,
            'module' => AuditLog::MODULE_AUTHENTICATION,
            'action' => AuditLog::ACTION_LOGIN,
            'description' => 'System event.',
        ]);
        Sanctum::actingAs($administrator);

        $this->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.actor', null);
    }

    private function user(string $roleName, int $officeId, string $email): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        $user = User::create([
            'name' => "{$roleName} Actor",
            'email' => $email,
            'password' => Hash::make('sensitive-password-marker'),
            'role_id' => $role->id,
            'office_id' => $officeId,
        ]);

        $user->forceFill([
            'remember_token' => 'sensitive-remember-token-marker',
        ])->save();

        return $user;
    }

    private function audit(
        User $actor,
        int $recordId,
        string $module = AuditLog::MODULE_DOCUMENTS,
        string $action = AuditLog::ACTION_UPDATED
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $actor->id,
            'module' => $module,
            'action' => $action,
            'record_id' => $recordId,
            'description' => "Safe audit {$recordId}.",
            'ip_address' => '127.0.0.1',
            'user_agent' => implode(' ', [
                'sensitive-user-agent-marker',
                'Bearer sensitive-token-marker',
                'select * from audit_logs',
                'C:\\private\\attachment.pdf',
                'private-filename.pdf',
                'RuntimeException',
            ]),
        ]);
    }

    private function prohibitedMarkers(User $actor): array
    {
        return [
            'user_id',
            'user_agent',
            'updated_at',
            $actor->email,
            $actor->password,
            $actor->remember_token,
            'sensitive-password-marker',
            'sensitive-remember-token-marker',
            'sensitive-user-agent-marker',
            'Bearer sensitive-token-marker',
            'select * from audit_logs',
            'C:\\private\\attachment.pdf',
            'private-filename.pdf',
            'RuntimeException',
        ];
    }
}
