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

class Process13BDocumentCompleteTest extends TestCase
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
            'document_processing_logs',
            'document_routes',
            'documents',
            'route_actions',
            'processing_actions',
            'document_statuses',
            'users',
            'offices',
            'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_current_office_user_can_complete_document_with_history_and_audit(): void
    {
        $officeId = $this->createOffice('MAYOR');
        $user = $this->createUser('Office User', $officeId);
        $document = $this->createDocument($officeId);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/documents/{$document->id}/complete")
            ->assertOk()
            ->assertJsonPath('message', 'Document completed successfully.')
            ->assertJsonPath('document.id', $document->id)
            ->assertJsonPath('document.status.status_name', 'Completed')
            ->assertJsonPath('document.completed_by.id', $user->id);

        $fresh = $document->fresh();
        $this->assertSame($this->statusId('Completed'), $fresh->status_id);
        $this->assertSame($user->id, $fresh->completed_by);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNotNull($response->json('document.completed_at'));

        $this->assertDatabaseHas('document_processing_logs', [
            'document_id' => $document->id,
            'office_id' => $officeId,
            'user_id' => $user->id,
            'processing_action_id' => $this->actionId('FOR_ACTION'),
            'event_type' => 'completed',
            'processing_note' => null,
            'event_note' => 'Document completed.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_DOCUMENTS,
            'action' => AuditLog::ACTION_COMPLETED,
            'record_id' => $document->id,
            'user_id' => $user->id,
            'description' => 'Document completed.',
        ]);

        $this->assertSame(1, DB::table('document_processing_logs')->count());
        $this->assertSame(1, AuditLog::count());
        $this->assertSame(0, DB::table('document_routes')->count());
    }

    public function test_wrong_office_no_office_and_viewer_cannot_complete(): void
    {
        $holdingOfficeId = $this->createOffice('HOLD');
        $otherOfficeId = $this->createOffice('OTHER');

        $wrongOfficeDocument = $this->createDocument($holdingOfficeId);
        Sanctum::actingAs($this->createUser('Office User', $otherOfficeId));
        $this->postJson("/api/documents/{$wrongOfficeDocument->id}/complete")
            ->assertForbidden();
        $this->assertIncompleteState($wrongOfficeDocument->id);

        $noOfficeDocument = $this->createDocument($holdingOfficeId);
        Sanctum::actingAs($this->createUser('Office User', null));
        $this->postJson("/api/documents/{$noOfficeDocument->id}/complete")
            ->assertForbidden();
        $this->assertIncompleteState($noOfficeDocument->id);

        $viewerDocument = $this->createDocument($holdingOfficeId);
        Sanctum::actingAs($this->createUser('Viewer', $holdingOfficeId));
        $this->postJson("/api/documents/{$viewerDocument->id}/complete")
            ->assertForbidden();
        $this->assertIncompleteState($viewerDocument->id);

        $this->assertSame(0, DB::table('document_processing_logs')->count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_pending_already_completed_and_archived_documents_are_blocked(): void
    {
        $sourceOfficeId = $this->createOffice('SRC');
        $destinationOfficeId = $this->createOffice('DST');
        $actor = $this->createUser('Office User', $sourceOfficeId);

        $pendingDocument = $this->createDocument($sourceOfficeId);
        DB::table('document_routes')->insert([
            'document_id' => $pendingDocument->id,
            'from_office_id' => $sourceOfficeId,
            'to_office_id' => $destinationOfficeId,
            'forwarded_by' => $actor->id,
            'forwarded_at' => now(),
            'status_id' => $this->statusId('Forwarded'),
            'action_id' => $this->routeActionId('Forward'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($actor);
        $this->postJson("/api/documents/{$pendingDocument->id}/complete")
            ->assertConflict();
        $this->assertIncompleteState($pendingDocument->id);

        $completedDocument = $this->createDocument($sourceOfficeId, 'Completed');
        $this->postJson("/api/documents/{$completedDocument->id}/complete")
            ->assertConflict();

        $archivedDocument = $this->createDocument($sourceOfficeId, 'Archived');
        $this->postJson("/api/documents/{$archivedDocument->id}/complete")
            ->assertConflict();

        $this->assertSame(0, DB::table('document_processing_logs')->count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_completed_documents_cannot_be_forwarded_or_processed(): void
    {
        $officeId = $this->createOffice('DONE');
        $destinationOfficeId = $this->createOffice('NEXT');
        $user = $this->createUser('Office User', $officeId);
        $document = $this->createDocument($officeId, 'Completed', [
            'completed_at' => now(),
            'completed_by' => $user->id,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/documents/{$document->id}/forward", [
            'to_office_id' => $destinationOfficeId,
        ])->assertConflict();

        $this->putJson("/api/documents/{$document->id}/processing", [
            'current_action_id' => $this->actionId('UNDER_REVIEW'),
            'processing_note' => 'Should not be saved.',
        ])->assertConflict();

        $fresh = $document->fresh();
        $this->assertSame($this->statusId('Completed'), $fresh->status_id);
        $this->assertSame($officeId, $fresh->current_office_id);
        $this->assertSame($this->actionId('FOR_ACTION'), $fresh->current_action_id);
        $this->assertNull($fresh->processing_note);
        $this->assertSame(0, DB::table('document_routes')->count());
        $this->assertSame(0, DB::table('document_processing_logs')->count());
        $this->assertSame(0, AuditLog::count());
    }

    private function assertIncompleteState(int $documentId): void
    {
        $document = Document::findOrFail($documentId);

        $this->assertSame($this->statusId('Received'), $document->status_id);
        $this->assertNull($document->completed_at);
        $this->assertNull($document->completed_by);
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
            'status_id' => $this->statusId($status),
            'origin_office_id' => $officeId,
            'current_office_id' => $officeId,
            'current_action_id' => $this->actionId('FOR_ACTION'),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function statusId(string $name): int
    {
        return (int) DB::table('document_statuses')
            ->where('status_name', $name)
            ->value('id');
    }

    private function actionId(string $code): int
    {
        return (int) DB::table('processing_actions')
            ->where('action_code', $code)
            ->value('id');
    }

    private function routeActionId(string $name): int
    {
        return (int) DB::table('route_actions')
            ->where('action_name', $name)
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
            $table->timestamps();
        });

        Schema::create('offices', function (Blueprint $table): void {
            $table->id();
            $table->string('office_name', 150);
            $table->string('office_code', 20)->unique();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id')->nullable();
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
            $table->unsignedBigInteger('status_id')->nullable();
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
