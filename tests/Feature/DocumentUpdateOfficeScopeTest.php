<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
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
    }

    protected function tearDown(): void
    {
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

        $this->patchJson('/api/documents/'.$document->id, [
            'title' => 'Unauthorized update',
        ])->assertForbidden();

        $this->assertSame('Original title', $document->fresh()->title);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_user_without_office_is_forbidden_without_audit(): void
    {
        $document = $this->createDocument($this->createOffice('CURRENT'));
        $user = $this->createUser('Records Officer', null);
        Sanctum::actingAs($user);

        $this->patchJson('/api/documents/'.$document->id, [
            'title' => 'Unauthorized update',
        ])->assertForbidden();

        $this->assertSame('Original title', $document->fresh()->title);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_unauthorized_role_remains_blocked_by_middleware(): void
    {
        $officeId = $this->createOffice('CURRENT');
        $document = $this->createDocument($officeId);
        $viewer = $this->createUser('Viewer', $officeId);
        Sanctum::actingAs($viewer);

        $this->patchJson('/api/documents/'.$document->id, [
            'title' => 'Unauthorized update',
        ])->assertForbidden();

        $this->assertSame('Original title', $document->fresh()->title);
        $this->assertSame(0, AuditLog::count());
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
}
