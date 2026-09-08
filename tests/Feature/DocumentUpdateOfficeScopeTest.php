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
use PHPUnit\Framework\Attributes\DataProvider;
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

        Schema::create('document_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('qr_token')->unique();
            $table->string('status')->default('unused');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamps();
        });

        Schema::create('document_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('document_attachments');
        Schema::dropIfExists('document_qr_codes');
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

    public static function editingRoles(): array
    {
        return [['Administrator'], ['Records Officer']];
    }

    #[DataProvider('editingRoles')]
    public function test_locked_update_rejects_custody_changed_after_initial_observation(string $role): void
    {
        [$user, $document, $otherOffice] = $this->custodyFixture($role);
        $before = $this->businessSnapshot();
        $expected = $before;
        $expected['documents'][0]['current_office_id'] = $otherOffice;

        $this->afterInitialDocumentRead($document, function () use ($document, $otherOffice): void {
            DB::table('documents')->where('id', $document->id)->update([
                'current_office_id' => $otherOffice,
            ]);
        }, function () use ($document): void {
            $this->patchJson('/api/documents/'.$document->id, [
                'title' => 'Must not change',
                'description' => 'Must not change either',
                'document_date' => '2026-09-08',
                'due_date' => '2026-09-09',
            ])->assertForbidden()->assertExactJson([
                'message' => 'You cannot update this document because it is not currently assigned to your office.',
            ]);
        });

        $this->assertSame($expected, $this->businessSnapshot());
        $this->assertSame(0, AuditLog::count());
    }

    #[DataProvider('editingRoles')]
    public function test_valid_locked_update_uses_reloaded_state_and_audits_exactly_once(string $role): void
    {
        [$user, $document] = $this->custodyFixture($role);
        $this->freezeTime();
        $before = $this->businessSnapshot();

        $this->afterInitialDocumentRead($document, function () use ($document): void {
            DB::table('documents')->where('id', $document->id)->update([
                'description' => 'Newer stored description',
            ]);
        }, function () use ($document): void {
            $this->patchJson('/api/documents/'.$document->id, [
                'title' => 'Locked update',
            ])->assertOk()
                ->assertJsonPath('message', 'Document updated successfully')
                ->assertJsonPath('document.id', $document->id)
                ->assertJsonPath('document.title', 'Locked update')
                ->assertJsonPath('document.description', 'Newer stored description');
        });

        $after = $this->businessSnapshot();
        $before['documents'][0]['title'] = 'Locked update';
        $before['documents'][0]['description'] = 'Newer stored description';
        $before['documents'][0]['updated_at'] = now()->toDateTimeString();
        $this->assertCount(1, $after['audits']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'module' => AuditLog::MODULE_DOCUMENTS,
            'action' => AuditLog::ACTION_UPDATED,
            'record_id' => $document->id,
            'description' => 'Document updated successfully.',
        ]);
        $after['audits'] = [];
        $this->assertSame($before, $after);
    }

    #[DataProvider('editingRoles')]
    public function test_concurrent_disappearance_keeps_existing_not_found_behavior(string $role): void
    {
        config(['app.debug' => false]);
        [, $document] = $this->custodyFixture($role);
        $before = $this->businessSnapshot();
        $response = null;

        $this->afterInitialDocumentRead($document, function () use ($document): void {
            DB::table('documents')->where('id', $document->id)->delete();
        }, function () use ($document, &$response): void {
            $response = $this->patchJson('/api/documents/'.$document->id, ['title' => 'Must not return success'])
                ->assertNotFound();
        }, 1);

        $this->patchJson('/api/documents/'.$document->id, ['title' => 'Missing'])
            ->assertNotFound()->assertExactJson($response->json());
        $this->assertSame(['message'], array_keys($response->json()));
        array_shift($before['documents']);
        $this->assertSame($before, $this->businessSnapshot());
    }

    private function afterInitialDocumentRead(
        Document $target,
        callable $change,
        callable $request,
        int $expectedReads = 2
    ): void {
        $originalDispatcher = Document::getEventDispatcher();
        Document::setEventDispatcher(clone $originalDispatcher);
        $reads = 0;

        try {
            Document::retrieved(function (Document $document) use ($target, $change, &$reads): void {
                if (!$document->is($target)) {
                    return;
                }

                $reads++;
                if ($reads === 1) {
                    $this->assertSame(0, DB::transactionLevel());
                    // Query builder avoids recursive model events; the old instance stays stale.
                    $change();
                } else {
                    $this->assertSame(1, DB::transactionLevel());
                }
            });
            $request();
            $this->assertSame($expectedReads, $reads);
        } finally {
            Document::setEventDispatcher($originalDispatcher);
        }
    }

    private function custodyFixture(string $role): array
    {
        $office = $this->createOffice('CURRENT');
        $otherOffice = $this->createOffice('OTHER');
        $user = $this->createUser($role, $office);
        $otherUser = $this->createUser('Viewer', $otherOffice);
        $target = $this->createDocument($office);
        $unrelated = $this->createDocument($otherOffice);
        foreach ([$target, $unrelated] as $document) {
            DB::table('document_routes')->insert([
                'document_id' => $document->id,
                'from_office_id' => $office,
                'to_office_id' => $otherOffice,
            ]);
            DB::table('document_processing_logs')->insert([
                'document_id' => $document->id,
                'event_type' => 'processing',
            ]);
            DB::table('document_qr_codes')->insert([
                'document_id' => $document->id,
                'qr_token' => 'isolated-test-qr-'.$document->id,
                'status' => 'registered',
            ]);
            // Metadata fixtures only: no attachment files or policy actions.
            DB::table('document_attachments')->insert([
                'document_id' => $document->id,
                'original_filename' => 'fixture.pdf',
                'stored_filename' => 'fixture-'.$document->id.'.pdf',
                'file_path' => 'isolated/fixture-'.$document->id.'.pdf',
                'uploaded_by' => $otherUser->id,
            ]);
        }
        $user->createToken('target-office-session');
        $otherUser->createToken('unrelated-session');
        Sanctum::actingAs($user);

        return [$user, $target, $otherOffice];
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
            'qr_records' => DB::table('document_qr_codes')->orderBy('id')->get()
                ->map(fn ($row): array => (array) $row)->all(),
            'attachments' => DB::table('document_attachments')->orderBy('id')->get()
                ->map(fn ($row): array => (array) $row)->all(),
            'users' => DB::table('users')->orderBy('id')->get([
                'id', 'name', 'email', 'role_id', 'department_id', 'office_id',
                'created_at', 'updated_at',
            ])->map(fn ($row): array => (array) $row)->all(),
        ];
    }
}
