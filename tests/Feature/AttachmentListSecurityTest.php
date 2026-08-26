<?php

namespace Tests\Feature;

use App\Models\DocumentAttachment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttachmentListSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
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

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('origin_office_id')->nullable();
            $table->unsignedBigInteger('current_office_id')->nullable();
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
        Schema::dropIfExists('documents');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');

        parent::tearDown();
    }

    public function test_current_office_user_can_list_safe_attachment_metadata(): void
    {
        [$documentId, $attachment] = $this->createDocumentAndAttachment();
        Sanctum::actingAs($this->createUser('Office User', 20));

        $response = $this->getJson("/api/documents/{$documentId}/attachments");

        $response->assertOk()
            ->assertJsonPath('0.id', $attachment->id)
            ->assertJsonPath('0.original_filename', 'meeting-notes.pdf')
            ->assertJsonPath('0.mime_type', 'application/pdf')
            ->assertJsonPath('0.file_size', 15)
            ->assertJsonPath('0.uploaded_by.name', 'Uploader')
            ->assertJsonMissingPath('0.stored_filename')
            ->assertJsonMissingPath('0.file_path');
    }

    public function test_origin_office_user_can_list_attachments(): void
    {
        [$documentId] = $this->createDocumentAndAttachment();
        Sanctum::actingAs($this->createUser('Viewer', 10));

        $this->getJson("/api/documents/{$documentId}/attachments")
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_unrelated_office_receives_no_attachment_metadata(): void
    {
        [$documentId] = $this->createDocumentAndAttachment();
        Sanctum::actingAs($this->createUser('Office User', 30));

        $this->getJson("/api/documents/{$documentId}/attachments")
            ->assertForbidden()
            ->assertExactJson([
                'message' =>
                    'You are not authorized to access attachments for this document.',
            ]);
    }

    public function test_user_without_office_receives_no_attachment_metadata(): void
    {
        [$documentId] = $this->createDocumentAndAttachment();
        Sanctum::actingAs($this->createUser('Administrator', null));

        $this->getJson("/api/documents/{$documentId}/attachments")
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Your user account is not assigned to an office.',
            ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        [$documentId] = $this->createDocumentAndAttachment();

        $this->getJson("/api/documents/{$documentId}/attachments")
            ->assertUnauthorized();
    }

    public function test_all_existing_roles_with_attachment_view_keep_scoped_access(): void
    {
        [$documentId] = $this->createDocumentAndAttachment();

        foreach (['Administrator', 'Records Officer', 'Office User', 'Viewer'] as $index => $roleName) {
            Sanctum::actingAs($this->createUser(
                $roleName,
                20,
                "role{$index}@example.test"
            ));

            $this->getJson("/api/documents/{$documentId}/attachments")
                ->assertOk()
                ->assertJsonCount(1);
        }
    }

    public function test_role_without_attachment_view_remains_forbidden_by_middleware(): void
    {
        [$documentId] = $this->createDocumentAndAttachment();
        Sanctum::actingAs($this->createUser('Unknown Role', 20));

        $this->getJson("/api/documents/{$documentId}/attachments")
            ->assertForbidden();
    }

    public function test_download_behavior_remains_current_or_origin_office_scoped(): void
    {
        [, $attachment] = $this->createDocumentAndAttachment();
        Storage::disk('local')->put($attachment->file_path, 'attachment body');

        Sanctum::actingAs($this->createUser(
            'Office User',
            20,
            'current@example.test'
        ));
        $this->get("/api/attachments/{$attachment->id}/download")
            ->assertOk()
            ->assertHeader('content-disposition');

        Sanctum::actingAs($this->createUser(
            'Viewer',
            10,
            'origin@example.test'
        ));
        $this->get("/api/attachments/{$attachment->id}/download")
            ->assertOk();

        Sanctum::actingAs($this->createUser(
            'Office User',
            30,
            'unrelated@example.test'
        ));
        $this->getJson("/api/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }

    private function createDocumentAndAttachment(): array
    {
        $documentId = Schema::getConnection()->table('documents')->insertGetId([
            'origin_office_id' => 10,
            'current_office_id' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uploader = $this->createUser(
            'Office User',
            20,
            'uploader@example.test',
            'Uploader'
        );

        $attachment = DocumentAttachment::query()->create([
            'document_id' => $documentId,
            'original_filename' => 'meeting-notes.pdf',
            'stored_filename' => 'internal-storage-name.pdf',
            'file_path' => "document_attachments/{$documentId}/internal-storage-name.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 15,
            'uploaded_by' => $uploader->id,
        ]);

        return [$documentId, $attachment];
    }

    private function createUser(
        string $roleName,
        ?int $officeId,
        ?string $email = null,
        ?string $name = null
    ): User {
        $role = Role::query()->firstOrCreate(['name' => $roleName]);

        return User::query()->create([
            'name' => $name ?? $roleName,
            'email' => $email ?? strtolower(str_replace(' ', '.', $roleName)).'@example.test',
            'password' => Hash::make('test-password'),
            'role_id' => $role->id,
            'office_id' => $officeId,
        ]);
    }
}
