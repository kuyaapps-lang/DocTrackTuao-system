<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DocumentAttachment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class Process5DAttachmentAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

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
        foreach (['audit_logs', 'document_attachments', 'documents', 'users', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_upload_creates_one_file_row_and_safe_audit(): void
    {
        [$documentId, $user] = $this->documentAndUser();
        Sanctum::actingAs($user);
        $secretName = 'private-sensitive-name.pdf';
        $content = 'private uploaded content';

        $response = $this->postJson("/api/documents/{$documentId}/attachments", [
            'file' => UploadedFile::fake()->createWithContent($secretName, $content),
        ])->assertCreated();

        $attachment = DocumentAttachment::sole();
        $audit = AuditLog::sole();
        Storage::disk('local')->assertExists($attachment->file_path);
        $this->assertSame(AuditLog::MODULE_ATTACHMENTS, $audit->module);
        $this->assertSame(AuditLog::ACTION_UPLOADED, $audit->action);
        $this->assertSame($attachment->id, $audit->record_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertStringContainsString("document ID {$documentId}", $audit->description);
        $this->assertStringContainsString('bytes:', $audit->description);

        foreach ([$secretName, $attachment->stored_filename, $attachment->file_path, $content] as $secret) {
            $this->assertStringNotContainsString($secret, $audit->description);
        }
        $this->assertSame(1, AuditLog::count());
        $response->assertJsonPath('attachment.id', $attachment->id);
    }

    public function test_rejected_uploads_create_no_file_row_or_audit(): void
    {
        [$documentId] = $this->documentAndUser();

        $this->postJson("/api/documents/{$documentId}/attachments", [
            'file' => UploadedFile::fake()->create('blocked.exe', 1),
        ])->assertUnauthorized();

        Sanctum::actingAs($this->user('Viewer', 20, 'viewer@example.test'));
        $this->postJson("/api/documents/{$documentId}/attachments", [
            'file' => $this->pdf(),
        ])->assertForbidden();

        Sanctum::actingAs($this->user('Office User', null, 'no-office@example.test'));
        $this->postJson("/api/documents/{$documentId}/attachments", ['file' => $this->pdf()])
            ->assertForbidden();

        Sanctum::actingAs($this->user('Office User', 99, 'wrong-office@example.test'));
        $this->postJson("/api/documents/{$documentId}/attachments", ['file' => $this->pdf()])
            ->assertForbidden();

        Sanctum::actingAs($this->user('Office User', 20, 'valid-office@example.test'));
        $this->postJson("/api/documents/{$documentId}/attachments", [
            'file' => UploadedFile::fake()->create('blocked.exe', 1),
        ])->assertUnprocessable();

        $this->assertSame(0, DocumentAttachment::count());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_delete_removes_file_and_row_and_creates_one_safe_audit(): void
    {
        [$documentId, $user] = $this->documentAndUser();
        $attachment = $this->attachment($documentId, $user);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/attachments/{$attachment->id}")->assertOk();

        Storage::disk('local')->assertMissing($attachment->file_path);
        $this->assertDatabaseMissing('document_attachments', ['id' => $attachment->id]);
        $audit = AuditLog::sole();
        $this->assertSame(AuditLog::MODULE_ATTACHMENTS, $audit->module);
        $this->assertSame(AuditLog::ACTION_DELETED, $audit->action);
        $this->assertSame($attachment->id, $audit->record_id);
        $this->assertSame("Attachment deleted from document ID {$documentId}.", $audit->description);
        foreach ([$attachment->original_filename, $attachment->stored_filename, $attachment->file_path] as $secret) {
            $this->assertStringNotContainsString($secret, $audit->description);
        }
    }

    public function test_forbidden_and_failed_deletes_create_no_audit(): void
    {
        [$documentId, $user] = $this->documentAndUser();
        $attachment = $this->attachment($documentId, $user);

        Sanctum::actingAs($this->user('Viewer', 20, 'viewer@example.test'));
        $this->deleteJson("/api/attachments/{$attachment->id}")->assertForbidden();

        Sanctum::actingAs($this->user('Office User', 99, 'wrong@example.test'));
        $this->deleteJson("/api/attachments/{$attachment->id}")->assertForbidden();

        $this->assertDatabaseHas('document_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($attachment->file_path);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_filesystem_delete_failure_keeps_row_and_creates_no_audit(): void
    {
        [$documentId, $user] = $this->documentAndUser();
        $attachment = $this->attachment($documentId, $user);
        Sanctum::actingAs($user);

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with($attachment->file_path)->andReturn(true);
        $disk->shouldReceive('delete')->once()->with($attachment->file_path)->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->once()->andReturn($disk);

        $this->deleteJson("/api/attachments/{$attachment->id}")
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Attachment could not be deleted.']);

        $this->assertDatabaseHas('document_attachments', ['id' => $attachment->id]);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_audit_failure_does_not_break_upload_or_delete(): void
    {
        [$documentId, $user] = $this->documentAndUser();
        Sanctum::actingAs($user);
        Log::spy();
        Schema::drop('audit_logs');

        $response = $this->postJson("/api/documents/{$documentId}/attachments", ['file' => $this->pdf()])
            ->assertCreated();
        $attachmentId = $response->json('attachment.id');
        $attachment = DocumentAttachment::findOrFail($attachmentId);
        Storage::disk('local')->assertExists($attachment->file_path);

        $this->deleteJson("/api/attachments/{$attachmentId}")->assertOk();
        Storage::disk('local')->assertMissing($attachment->file_path);
        $this->assertDatabaseMissing('document_attachments', ['id' => $attachmentId]);
        Log::shouldHaveReceived('warning')->twice();
    }

    private function documentAndUser(): array
    {
        $documentId = Schema::getConnection()->table('documents')->insertGetId([
            'origin_office_id' => 10,
            'current_office_id' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$documentId, $this->user('Office User', 20)];
    }

    private function user(string $roleName, ?int $officeId, string $email = 'user@example.test'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        return User::create([
            'name' => $roleName,
            'email' => $email,
            'password' => Hash::make('test-password'),
            'role_id' => $role->id,
            'office_id' => $officeId,
        ]);
    }

    private function attachment(int $documentId, User $user): DocumentAttachment
    {
        $path = "document_attachments/{$documentId}/internal-name.pdf";
        Storage::disk('local')->put($path, 'private attachment content');
        return DocumentAttachment::create([
            'document_id' => $documentId,
            'original_filename' => 'private-name.pdf',
            'stored_filename' => 'internal-name.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 26,
            'uploaded_by' => $user->id,
        ]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('test.pdf', 1, 'application/pdf');
    }
}
