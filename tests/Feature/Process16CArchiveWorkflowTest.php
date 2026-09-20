<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Process16CArchiveWorkflowTest extends TestCase
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
            'personal_access_tokens',
            'audit_logs',
            'document_attachments',
            'document_processing_logs',
            'document_routes',
            'documents',
            'route_actions',
            'processing_actions',
            'confidentiality_levels',
            'priorities',
            'document_types',
            'document_statuses',
            'users',
            'offices',
            'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_current_office_user_can_archive_completed_document_with_history_and_audit(): void
    {
        $officeId = $this->createOffice('MAYOR');
        $user = $this->createUser('Office User', $officeId);
        $document = $this->createDocument($officeId, 'Completed', [
            'completed_at' => now(),
            'completed_by' => $user->id,
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/documents/{$document->id}/archive")
            ->assertOk()
            ->assertJsonPath('message', 'Document archived successfully.')
            ->assertJsonPath('document.id', $document->id)
            ->assertJsonPath('document.status.status_name', 'Archived')
            ->assertJsonPath('document.archived_by.id', $user->id);

        $fresh = $document->fresh();
        $this->assertSame($this->statusId('Archived'), $fresh->status_id);
        $this->assertSame($user->id, $fresh->archived_by);
        $this->assertNotNull($fresh->archived_at);
        $this->assertNotNull($response->json('document.archived_at'));

        $this->assertDatabaseHas('document_processing_logs', [
            'document_id' => $document->id,
            'office_id' => $officeId,
            'user_id' => $user->id,
            'processing_action_id' => $this->actionId('FOR_ACTION'),
            'event_type' => 'archived',
            'processing_note' => null,
            'event_note' => 'Document archived.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_DOCUMENTS,
            'action' => AuditLog::ACTION_ARCHIVED,
            'record_id' => $document->id,
            'user_id' => $user->id,
            'description' => 'Document archived.',
        ]);

        $this->assertSame(1, DB::table('document_processing_logs')->count());
        $this->assertSame(1, AuditLog::count());
    }

    public function test_only_completed_documents_can_be_archived_once(): void
    {
        $officeId = $this->createOffice('REC');
        $user = $this->createUser('Records Officer', $officeId);
        Sanctum::actingAs($user);

        $received = $this->createDocument($officeId, 'Received');
        $this->postJson("/api/documents/{$received->id}/archive")
            ->assertConflict();
        $this->assertArchiveState($received->id, 'Received', null);

        $archived = $this->createDocument($officeId, 'Archived', [
            'completed_at' => now(),
            'completed_by' => $user->id,
            'archived_at' => now(),
            'archived_by' => $user->id,
        ]);
        $this->postJson("/api/documents/{$archived->id}/archive")
            ->assertConflict();

        $this->assertSame(0, DB::table('document_processing_logs')->count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_wrong_office_no_office_viewer_and_pending_route_are_blocked(): void
    {
        $holdingOfficeId = $this->createOffice('HOLD');
        $otherOfficeId = $this->createOffice('OTHER');

        $wrongOfficeDocument = $this->completedDocument($holdingOfficeId);
        Sanctum::actingAs($this->createUser('Office User', $otherOfficeId));
        $this->postJson("/api/documents/{$wrongOfficeDocument->id}/archive")
            ->assertForbidden();
        $this->assertArchiveState($wrongOfficeDocument->id, 'Completed', null);

        $noOfficeDocument = $this->completedDocument($holdingOfficeId);
        Sanctum::actingAs($this->createUser('Office User', null));
        $this->postJson("/api/documents/{$noOfficeDocument->id}/archive")
            ->assertForbidden();
        $this->assertArchiveState($noOfficeDocument->id, 'Completed', null);

        $viewerDocument = $this->completedDocument($holdingOfficeId);
        Sanctum::actingAs($this->createUser('Viewer', $holdingOfficeId));
        $this->postJson("/api/documents/{$viewerDocument->id}/archive")
            ->assertForbidden();
        $this->assertArchiveState($viewerDocument->id, 'Completed', null);

        $actor = $this->createUser('Office User', $holdingOfficeId);
        $pendingDocument = $this->completedDocument($holdingOfficeId, $actor->id);
        DB::table('document_routes')->insert([
            'document_id' => $pendingDocument->id,
            'from_office_id' => $holdingOfficeId,
            'to_office_id' => $otherOfficeId,
            'forwarded_by' => $actor->id,
            'forwarded_at' => now(),
            'status_id' => $this->statusId('Forwarded'),
            'action_id' => $this->routeActionId('Forward'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($actor);
        $this->postJson("/api/documents/{$pendingDocument->id}/archive")
            ->assertConflict();
        $this->assertArchiveState($pendingDocument->id, 'Completed', null);

        $this->assertSame(0, DB::table('document_processing_logs')->count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_complete_is_blocked_after_archive(): void
    {
        $officeId = $this->createOffice('DONE');
        $user = $this->createUser('Office User', $officeId);
        $document = $this->createDocument($officeId, 'Archived', [
            'completed_at' => now(),
            'completed_by' => $user->id,
            'archived_at' => now(),
            'archived_by' => $user->id,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/documents/{$document->id}/complete")
            ->assertConflict();

        $fresh = $document->fresh();
        $this->assertSame($this->statusId('Archived'), $fresh->status_id);
        $this->assertNotNull($fresh->archived_at);
        $this->assertSame(0, DB::table('document_processing_logs')->count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_terminal_documents_block_workflow_edit_and_attachment_mutations(): void
    {
        foreach (['Completed', 'Archived'] as $status) {
            $officeId = $this->createOffice(strtoupper(substr($status, 0, 4)).uniqid());
            $destinationOfficeId = $this->createOffice('DST'.uniqid());
            $user = $this->createUser('Records Officer', $officeId);
            $document = $this->createDocument($officeId, $status, [
                'completed_at' => now(),
                'completed_by' => $user->id,
                'archived_at' => $status === 'Archived' ? now() : null,
                'archived_by' => $status === 'Archived' ? $user->id : null,
            ]);
            $attachment = DocumentAttachment::create([
                'document_id' => $document->id,
                'original_filename' => 'archive-test.pdf',
                'stored_filename' => 'archive-test.pdf',
                'file_path' => 'document_attachments/'.$document->id.'/archive-test.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 12,
                'uploaded_by' => $user->id,
            ]);
            Sanctum::actingAs($user);

            $this->postJson("/api/documents/{$document->id}/forward", [
                'to_office_id' => $destinationOfficeId,
            ])->assertConflict();

            $this->putJson("/api/documents/{$document->id}/processing", [
                'current_action_id' => $this->actionId('UNDER_REVIEW'),
                'processing_note' => 'Should not be saved.',
            ])->assertConflict();

            $this->patchJson("/api/documents/{$document->id}", [
                'title' => 'Should not change',
            ])->assertConflict();

            $this->postJson("/api/documents/{$document->id}/attachments", [
                'file' => UploadedFile::fake()->create('blocked.pdf', 4, 'application/pdf'),
            ])->assertConflict();

            $this->deleteJson("/api/attachments/{$attachment->id}")
                ->assertConflict();

            $fresh = $document->fresh();
            $this->assertSame($this->statusId($status), $fresh->status_id);
            $this->assertSame('Lifecycle test document', $fresh->title);
        }

        $this->assertSame(0, DB::table('document_routes')->count());
        $this->assertSame(0, DB::table('document_processing_logs')->count());
    }

    public function test_public_tracking_shows_archived_status_without_internal_archive_metadata(): void
    {
        $officeId = $this->createOffice('PUB');
        $user = $this->createUser('Office User', $officeId);
        $document = $this->createDocument($officeId, 'Archived', [
            'completed_at' => now(),
            'completed_by' => $user->id,
            'archived_at' => now(),
            'archived_by' => $user->id,
        ]);

        $response = $this->getJson("/api/track/{$document->tracking_no}")
            ->assertOk()
            ->assertJsonPath('tracking_no', $document->tracking_no)
            ->assertJsonPath('status', 'Archived');

        $payload = $response->json();
        $this->assertArrayNotHasKey('archived_at', $payload);
        $this->assertArrayNotHasKey('archived_by', $payload);
        $this->assertArrayNotHasKey('processing_note', $payload);
    }

    private function completedDocument(int $officeId, ?int $userId = null): Document
    {
        return $this->createDocument($officeId, 'Completed', [
            'completed_at' => now(),
            'completed_by' => $userId,
        ]);
    }

    private function assertArchiveState(
        int $documentId,
        string $status,
        ?int $archivedBy
    ): void {
        $document = Document::findOrFail($documentId);

        $this->assertSame($this->statusId($status), $document->status_id);
        $this->assertSame($archivedBy, $document->archived_by);
        if ($archivedBy === null) {
            $this->assertNull($document->archived_at);
        } else {
            $this->assertNotNull($document->archived_at);
        }
    }

    private function createUser(string $roleName, ?int $officeId): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        return User::create([
            'name' => $roleName.' User '.uniqid(),
            'email' => uniqid('user').'@example.test',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'office_id' => $officeId,
        ]);
    }

    private function createOffice(string $code): int
    {
        return (int) DB::table('offices')->insertGetId([
            'office_name' => $code.' Office',
            'office_code' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createDocument(
        int $officeId,
        string $status = 'Received',
        array $overrides = []
    ): Document {
        return Document::create(array_replace([
            'tracking_no' => 'DOC-'.strtoupper(uniqid()),
            'title' => 'Lifecycle test document',
            'description' => 'Public-safe archive workflow test.',
            'document_type_id' => $this->lookupId('document_types', 'type_name', 'Letter'),
            'status_id' => $this->statusId($status),
            'priority_id' => $this->lookupId('priorities', 'priority_name', 'Normal'),
            'confidentiality_level_id' => $this->lookupId('confidentiality_levels', 'level_name', 'Public'),
            'origin_office_id' => $officeId,
            'current_office_id' => $officeId,
            'current_action_id' => $this->actionId('FOR_ACTION'),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function statusId(string $name): int
    {
        return $this->lookupId('document_statuses', 'status_name', $name);
    }

    private function actionId(string $code): int
    {
        return $this->lookupId('processing_actions', 'action_code', $code);
    }

    private function routeActionId(string $name): int
    {
        return $this->lookupId('route_actions', 'action_name', $name);
    }

    private function lookupId(string $table, string $column, string $value): int
    {
        return (int) DB::table($table)
            ->where($column, $value)
            ->value('id');
    }

    private function seedLookups(): void
    {
        foreach (['Received', 'Forwarded', 'Completed', 'Archived'] as $status) {
            DB::table('document_statuses')->insert([
                'status_name' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('document_types')->insert([
            'type_name' => 'Letter',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('priorities')->insert([
            'priority_name' => 'Normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('confidentiality_levels')->insert([
            'level_name' => 'Public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['FOR_ACTION', 'For Action'],
            ['UNDER_REVIEW', 'Under Review'],
        ] as [$code, $name]) {
            DB::table('processing_actions')->insert([
                'action_code' => $code,
                'action_name' => $name,
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('route_actions')->insert([
            'action_name' => 'Forward',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('offices', function (Blueprint $table): void {
            $table->id();
            $table->string('office_name', 150);
            $table->string('office_code', 50)->unique();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
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

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('document_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('status_name', 50);
            $table->string('color', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('document_types', function (Blueprint $table): void {
            $table->id();
            $table->string('type_name', 100);
            $table->timestamps();
        });

        Schema::create('priorities', function (Blueprint $table): void {
            $table->id();
            $table->string('priority_name', 100);
            $table->timestamps();
        });

        Schema::create('confidentiality_levels', function (Blueprint $table): void {
            $table->id();
            $table->string('level_name', 100);
            $table->timestamps();
        });

        Schema::create('processing_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('action_code', 50);
            $table->string('action_name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('route_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('action_name', 50);
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('tracking_no', 50)->unique();
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
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamps();
        });

        Schema::create('document_routes', function (Blueprint $table): void {
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

        Schema::create('document_processing_logs', function (Blueprint $table): void {
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

        Schema::create('document_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
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
