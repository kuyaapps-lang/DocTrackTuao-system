<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentWorkflowAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->seedLookups();
    }

    protected function tearDown(): void
    {
        foreach ([
            'audit_logs',
            'document_processing_logs',
            'document_routes',
            'document_qr_codes',
            'documents',
            'route_actions',
            'processing_actions',
            'confidentiality_levels',
            'priorities',
            'document_statuses',
            'document_types',
            'users',
            'offices',
            'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_create_produces_exactly_one_expected_audit_row(): void
    {
        $officeId = $this->createOffice('CREATE');
        $user = $this->createUser('Records Officer', $officeId);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/documents', [
            'title' => 'Created document',
            'document_type_id' => $this->lookupId('document_types'),
            'priority_id' => $this->lookupId('priorities'),
            'confidentiality_level_id' =>
                $this->lookupId('confidentiality_levels'),
            'origin_office_id' => $officeId,
            'document_date' => '2026-08-26',
        ])->assertCreated();

        $documentId = $response->json('document.id');
        $this->assertSingleAudit(
            AuditLog::MODULE_DOCUMENTS,
            AuditLog::ACTION_CREATED,
            $documentId,
            $user->id
        );
    }

    public function test_update_produces_exactly_one_expected_audit_row(): void
    {
        $officeId = $this->createOffice('UPDATE');
        $user = $this->createUser('Records Officer', $officeId);
        $document = $this->createDocument($officeId);
        Sanctum::actingAs($user);

        $this->patchJson('/api/documents/'.$document->id, [
            'title' => 'Updated document',
        ])->assertOk();

        $this->assertSingleAudit(
            AuditLog::MODULE_DOCUMENTS,
            AuditLog::ACTION_UPDATED,
            $document->id,
            $user->id
        );
    }

    public function test_delete_produces_exactly_one_expected_audit_row(): void
    {
        $officeId = $this->createOffice('DELETE');
        $user = $this->createUser('Administrator', $officeId);
        $document = $this->createDocument($officeId);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/documents/'.$document->id)
            ->assertOk();

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertSingleAudit(
            AuditLog::MODULE_DOCUMENTS,
            AuditLog::ACTION_DELETED,
            $document->id,
            $user->id
        );
    }

    public function test_forward_produces_exactly_one_expected_audit_row(): void
    {
        $sourceOfficeId = $this->createOffice('SOURCE');
        $destinationOfficeId = $this->createOffice('DEST');
        $user = $this->createUser('Office User', $sourceOfficeId);
        $document = $this->createDocument($sourceOfficeId);
        Sanctum::actingAs($user);

        $this->postJson('/api/documents/'.$document->id.'/forward', [
            'to_office_id' => $destinationOfficeId,
            'remarks' => 'For review',
        ])->assertCreated();

        $this->assertSingleAudit(
            AuditLog::MODULE_DOCUMENT_ROUTING,
            AuditLog::ACTION_FORWARDED,
            $document->id,
            $user->id
        );
    }

    public function test_receive_produces_exactly_one_expected_audit_row(): void
    {
        $sourceOfficeId = $this->createOffice('SOURCE');
        $destinationOfficeId = $this->createOffice('DEST');
        $sender = $this->createUser('Office User', $sourceOfficeId);
        $receiver = $this->createUser('Office User', $destinationOfficeId);
        $document = $this->createDocument($sourceOfficeId);
        $routeId = DB::table('document_routes')->insertGetId([
            'document_id' => $document->id,
            'from_office_id' => $sourceOfficeId,
            'to_office_id' => $destinationOfficeId,
            'forwarded_by' => $sender->id,
            'forwarded_at' => now(),
            'status_id' => $this->statusId('Forwarded'),
            'action_id' => $this->routeActionId('Forward'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($receiver);

        $this->postJson('/api/documents/'.$document->id.'/receive')
            ->assertOk();

        $this->assertDatabaseHas('document_routes', [
            'id' => $routeId,
            'received_by' => $receiver->id,
        ]);
        $this->assertSingleAudit(
            AuditLog::MODULE_DOCUMENT_ROUTING,
            AuditLog::ACTION_RECEIVED,
            $document->id,
            $receiver->id
        );
    }

    public function test_processing_action_and_note_create_one_combined_audit_row_without_note_text(): void
    {
        $officeId = $this->createOffice('PROCESS');
        $user = $this->createUser('Office User', $officeId);
        $document = $this->createDocument($officeId);
        $actionId = $this->processingActionId('UNDER_REVIEW');
        $note = 'Sensitive internal note that must not be audited.';
        Sanctum::actingAs($user);

        $this->putJson('/api/documents/'.$document->id.'/processing', [
            'current_action_id' => $actionId,
            'processing_note' => $note,
        ])->assertOk();

        $this->assertSingleAudit(
            AuditLog::MODULE_DOCUMENT_PROCESSING,
            AuditLog::ACTION_PROCESSING_UPDATED,
            $document->id,
            $user->id
        );
        $audit = AuditLog::sole();
        $this->assertStringContainsString('UNDER_REVIEW', $audit->description);
        $this->assertStringContainsString('note supplied: yes', $audit->description);
        $this->assertStringNotContainsString($note, $audit->description);
    }

    public function test_forbidden_role_and_wrong_office_attempts_create_no_audit_rows(): void
    {
        $currentOfficeId = $this->createOffice('CURRENT');
        $otherOfficeId = $this->createOffice('OTHER');
        $document = $this->createDocument($currentOfficeId);
        $actionId = $this->processingActionId('UNDER_REVIEW');

        Sanctum::actingAs($this->createUser('Viewer', $currentOfficeId));
        $this->putJson('/api/documents/'.$document->id.'/processing', [
            'current_action_id' => $actionId,
        ])->assertForbidden();

        Sanctum::actingAs($this->createUser('Office User', $otherOfficeId));
        $this->putJson('/api/documents/'.$document->id.'/processing', [
            'current_action_id' => $actionId,
        ])->assertForbidden();

        $this->assertSame(0, AuditLog::count());
        $this->assertNull($document->fresh()->current_action_id);
    }

    public function test_audit_persistence_failure_does_not_roll_back_primary_update(): void
    {
        $officeId = $this->createOffice('FAILURE');
        $user = $this->createUser('Records Officer', $officeId);
        $document = $this->createDocument($officeId);
        Sanctum::actingAs($user);
        Schema::drop('audit_logs');
        Log::spy();

        $this->patchJson('/api/documents/'.$document->id, [
            'title' => 'Update survives audit failure',
        ])->assertOk();

        $this->assertSame(
            'Update survives audit failure',
            $document->fresh()->title
        );
        Log::shouldHaveReceived('warning')->once();
    }

    private function assertSingleAudit(
        string $module,
        string $action,
        int $recordId,
        int $userId
    ): void {
        $this->assertSame(1, AuditLog::count());
        $this->assertDatabaseHas('audit_logs', [
            'module' => $module,
            'action' => $action,
            'record_id' => $recordId,
            'user_id' => $userId,
        ]);
    }

    private function createOffice(string $code): int
    {
        return DB::table('offices')->insertGetId([
            'office_name' => $code.' Office',
            'office_code' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(string $roleName, ?int $officeId): User
    {
        $role = Role::create(['name' => $roleName]);

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
            'title' => 'Original document',
            'origin_office_id' => $officeId,
            'current_office_id' => $officeId,
        ]);
    }

    private function lookupId(string $table): int
    {
        return (int) DB::table($table)->value('id');
    }

    private function statusId(string $name): int
    {
        return (int) DB::table('document_statuses')
            ->where('status_name', $name)
            ->value('id');
    }

    private function routeActionId(string $name): int
    {
        return (int) DB::table('route_actions')
            ->where('action_name', $name)
            ->value('id');
    }

    private function processingActionId(string $code): int
    {
        return (int) DB::table('processing_actions')
            ->where('action_code', $code)
            ->value('id');
    }

    private function seedLookups(): void
    {
        $now = now();
        DB::table('document_types')->insert([
            'type_name' => 'Memorandum',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach (['Pending', 'Forwarded', 'Received'] as $status) {
            DB::table('document_statuses')->insert([
                'status_name' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('priorities')->insert([
            'priority_name' => 'Normal',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('confidentiality_levels')->insert([
            'level_name' => 'Public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('route_actions')->insert([
            'action_name' => 'Forward',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            ['REGISTERED', 'Registered'],
            ['AWAITING_RECEIPT', 'Awaiting Receipt'],
            ['FOR_ACTION', 'For Action'],
            ['UNDER_REVIEW', 'Under Review'],
        ] as [$code, $name]) {
            DB::table('processing_actions')->insert([
                'action_code' => $code,
                'action_name' => $name,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function createSchema(): void
    {
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
            $table->string('type_name', 100);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('document_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('status_name', 50);
            $table->string('color', 20)->nullable();
            $table->timestamps();
        });
        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('priority_name', 30);
            $table->timestamps();
        });
        Schema::create('confidentiality_levels', function (Blueprint $table) {
            $table->id();
            $table->string('level_name', 50);
            $table->timestamps();
        });
        Schema::create('processing_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_code', 50);
            $table->string('action_name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('route_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_name', 50);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_no')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
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
        Schema::create('document_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('qr_token')->unique();
            $table->string('status', 20)->default('unused');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('document_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('from_office_id');
            $table->unsignedBigInteger('to_office_id');
            $table->unsignedBigInteger('forwarded_by');
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('action_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
        Schema::create('document_processing_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('office_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('processing_action_id')->nullable();
            $table->unsignedBigInteger('document_route_id')->nullable();
            $table->string('event_type', 50);
            $table->text('processing_note')->nullable();
            $table->string('event_note', 1000)->nullable();
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
    }
}
