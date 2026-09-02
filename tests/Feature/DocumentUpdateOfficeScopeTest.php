<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentUpdateOfficeScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('office_name', 150);
            $table->string('office_code', 20)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('type_name')->nullable();
            $table->timestamps();
        });

        Schema::create('document_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('status_name')->nullable();
            $table->timestamps();
        });

        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('priority_name')->nullable();
            $table->timestamps();
        });

        Schema::create('confidentiality_levels', function (Blueprint $table) {
            $table->id();
            $table->string('level_name')->nullable();
            $table->timestamps();
        });

        Schema::create('processing_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_code')->nullable();
            $table->string('action_name')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_no')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('document_type_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('priority_id')->nullable();
            $table->unsignedBigInteger('confidentiality_level_id')->nullable();
            $table->unsignedBigInteger('origin_office_id')->nullable();
            $table->unsignedBigInteger('current_office_id')->nullable();
            $table->unsignedBigInteger('current_action_id')->nullable();
            $table->text('processing_note')->nullable();
            $table->unsignedBigInteger('current_action_updated_by')->nullable();
            $table->timestamp('current_action_updated_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->date('document_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
        });

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

        Schema::create('document_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('from_office_id');
            $table->unsignedBigInteger('to_office_id');
            $table->timestamps();
        });

        Schema::create('document_processing_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('event_type');
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('document_processing_logs');
        Schema::dropIfExists('document_routes');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('processing_actions');
        Schema::dropIfExists('confidentiality_levels');
        Schema::dropIfExists('priorities');
        Schema::dropIfExists('document_statuses');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('users');
        Schema::dropIfExists('offices');
        Schema::dropIfExists('roles');

        parent::tearDown();
    }

    public function test_correct_office_authorized_user_can_update_and_creates_one_audit_row(): void
    {
        $officeId = $this->createOffice('CURRENT');
        $user = $this->createUser('Records Officer', $officeId);
        $document = $this->createDocument($officeId);
        Sanctum::actingAs($user);

        $this->patchJson('/api/documents/'.$document->id, [
            'title' => 'Authorized update',
        ])->assertOk()
            ->assertJsonPath('document.title', 'Authorized update');

        $this->assertSame(
            'Authorized update',
            $document->fresh()->title
        );
        $this->assertSame(1, AuditLog::count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'module' => AuditLog::MODULE_DOCUMENTS,
            'action' => AuditLog::ACTION_UPDATED,
            'record_id' => $document->id,
        ]);
    }

    public function test_wrong_office_authorized_user_is_forbidden_without_changes_or_audit(): void
    {
        $currentOfficeId = $this->createOffice('CURRENT');
        $otherOfficeId = $this->createOffice('OTHER');
        $user = $this->createUser('Records Officer', $otherOfficeId);
        $document = $this->createDocument($currentOfficeId);
        Sanctum::actingAs($user);

        $before = $this->businessSnapshot();
        $this->patchJson('/api/documents/'.$document->id, [
            'title' => 'Unauthorized update',
        ])->assertForbidden();
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_user_without_office_is_forbidden_without_audit(): void
    {
        $document = $this->createDocument($this->createOffice('CURRENT'));
        $user = $this->createUser('Records Officer', null);
        Sanctum::actingAs($user);

        $before = $this->businessSnapshot();
        $this->patchJson('/api/documents/'.$document->id, [
            'title' => 'Unauthorized update',
        ])->assertForbidden();
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_unauthorized_role_remains_blocked_by_middleware(): void
    {
        $officeId = $this->createOffice('CURRENT');
        $document = $this->createDocument($officeId);
        $viewer = $this->createUser('Viewer', $officeId);
        Sanctum::actingAs($viewer);

        $before = $this->businessSnapshot();
        $this->patchJson('/api/documents/'.$document->id, [
            'title' => 'Unauthorized update',
        ])->assertForbidden();
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_each_workflow_controlled_field_is_rejected_without_mutation(): void
    {
        $officeId = $this->createOffice('CURRENT');
        $otherOfficeId = $this->createOffice('OTHER');
        $user = $this->createUser('Records Officer', $officeId);
        $document = $this->createDocument($officeId);
        $statusId = Schema::getConnection()->table('document_statuses')
            ->insertGetId(['status_name' => 'Forwarded']);
        Sanctum::actingAs($user);

        foreach ([
            'origin_office_id' => $otherOfficeId,
            'current_office_id' => $otherOfficeId,
            'status_id' => $statusId,
        ] as $field => $value) {
            $before = $this->businessSnapshot();
            $this->patchJson('/api/documents/'.$document->id, [
                $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame($before, $this->businessSnapshot(), $field);
        }
    }

    public function test_protected_and_valid_fields_cannot_partially_update(): void
    {
        $officeId = $this->createOffice('CURRENT');
        $user = $this->createUser('Records Officer', $officeId);
        $document = $this->createDocument($officeId);
        Sanctum::actingAs($user);
        $before = $this->businessSnapshot();

        $this->patchJson('/api/documents/'.$document->id, [
            'title' => 'Must not be applied',
            'current_office_id' => $this->createOffice('OTHER'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_office_id');

        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_other_workflow_owned_fields_are_never_applied(): void
    {
        $officeId = $this->createOffice('CURRENT');
        $user = $this->createUser('Records Officer', $officeId);
        $document = $this->createDocument($officeId);
        Sanctum::actingAs($user);
        $before = $this->businessSnapshot();

        $this->patchJson('/api/documents/'.$document->id, [
            'tracking_no' => 'ATTEMPTED-TRACKING-NUMBER',
            'current_action_id' => 999999,
            'processing_note' => 'Attempted workflow note',
            'current_action_updated_by' => $user->id,
            'current_action_updated_at' => now()->addDay()->toDateTimeString(),
            'created_by' => $user->id,
            'routes' => [['to_office_id' => $officeId]],
        ])->assertOk();

        $after = $this->businessSnapshot();
        $this->assertSame($before['documents'], $after['documents']);
        $this->assertSame($before['routes'], $after['routes']);
        $this->assertSame($before['processing'], $after['processing']);
        $this->assertCount(1, $after['audits']);
        $this->assertSame($before['tokens'], $after['tokens']);
        $this->assertSame($before['token_count'], $after['token_count']);
    }

    public function test_validation_and_unauthenticated_failures_are_read_only(): void
    {
        $officeId = $this->createOffice('CURRENT');
        $user = $this->createUser('Records Officer', $officeId);
        $document = $this->createDocument($officeId);
        Sanctum::actingAs($user);
        $beforeValidation = $this->businessSnapshot();

        $this->patchJson('/api/documents/'.$document->id, [
            'title' => str_repeat('x', 256),
        ])->assertUnprocessable();
        $this->assertSame($beforeValidation, $this->businessSnapshot());

        app('auth')->forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $beforeUnauthenticated = $this->businessSnapshot();
        $this->patchJson('/api/documents/'.$document->id, [
            'title' => 'Unauthenticated update',
        ])->assertUnauthorized();
        $this->assertSame($beforeUnauthenticated, $this->businessSnapshot());
    }

    private function createOffice(string $code): int
    {
        return Schema::getConnection()->table('offices')->insertGetId([
            'office_name' => $code.' Office',
            'office_code' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(string $roleName, ?int $officeId): User
    {
        $role = Role::create([
            'name' => $roleName,
        ]);

        return User::create([
            'name' => $roleName.' Test User',
            'email' => strtolower(str_replace(' ', '.', $roleName)).
                '.'.$role->id.'@example.test',
            'password' => 'test-password',
            'role_id' => $role->id,
            'office_id' => $officeId,
        ]);
    }

    private function createDocument(int $officeId): Document
    {
        return Document::create([
            'tracking_no' => 'DOC-'.uniqid(),
            'title' => 'Original title',
            'origin_office_id' => $officeId,
            'current_office_id' => $officeId,
        ]);
    }

    private function businessSnapshot(): array
    {
        return [
            'documents' => DB::table('documents')->orderBy('id')->get()->map(
                fn ($row): array => (array) $row
            )->all(),
            'routes' => DB::table('document_routes')->orderBy('id')->get()->map(
                fn ($row): array => (array) $row
            )->all(),
            'processing' => DB::table('document_processing_logs')
                ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'audits' => DB::table('audit_logs')->orderBy('id')->get()->map(
                fn ($row): array => (array) $row
            )->all(),
            'tokens' => DB::table('personal_access_tokens')
                ->orderBy('id')
                ->get([
                    'id', 'tokenable_type', 'tokenable_id', 'name', 'abilities',
                    'last_used_at', 'expires_at', 'created_at', 'updated_at',
                ])
                ->map(fn ($row): array => (array) $row)->all(),
            'token_count' => DB::table('personal_access_tokens')->count(),
        ];
    }
}
