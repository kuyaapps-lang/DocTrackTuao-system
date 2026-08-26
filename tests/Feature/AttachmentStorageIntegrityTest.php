<?php

namespace Tests\Feature;

use App\Models\DocumentAttachment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AttachmentStorageIntegrityTest extends TestCase
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
            $table->string('original_filename')->unique();
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

    public function test_successful_upload_creates_one_private_file_and_database_row(): void
    {
        [$documentId, $user] = $this->createDocumentAndUser();
        Sanctum::actingAs($user);

        $response = $this->postJson(
            "/api/documents/{$documentId}/attachments",
            ['file' => $this->validPdf()]
        );

        $response->assertCreated()
            ->assertJsonPath('message', 'Attachment uploaded successfully.')
            ->assertJsonPath('attachment.original_filename', 'report.pdf');

        $this->assertSame(1, DocumentAttachment::count());
        $attachment = DocumentAttachment::sole();
        Storage::disk('local')->assertExists($attachment->file_path);
        $this->assertStringStartsWith(
            "document_attachments/{$documentId}/",
            $attachment->file_path
        );
    }

    public function test_false_storage_result_creates_neither_file_nor_database_row(): void
    {
        [$documentId, $user] = $this->createDocumentAndUser();
        Sanctum::actingAs($user);
        Log::spy();

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->once()->andReturn($disk);

        $this->postJson(
            "/api/documents/{$documentId}/attachments",
            ['file' => $this->validPdf()]
        )->assertStatus(500)
            ->assertExactJson([
                'message' => 'Attachment could not be stored.',
            ]);

        $this->assertSame(0, DocumentAttachment::count());
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_thrown_storage_failure_returns_only_a_safe_error(): void
    {
        [$documentId, $user] = $this->createDocumentAndUser();
        Sanctum::actingAs($user);
        Log::spy();

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')
            ->once()
            ->andThrow(new RuntimeException('Sensitive storage detail'));
        Storage::shouldReceive('disk')->with('local')->once()->andReturn($disk);

        $this->postJson(
            "/api/documents/{$documentId}/attachments",
            ['file' => $this->validPdf()]
        )->assertStatus(500)
            ->assertExactJson([
                'message' => 'Attachment could not be stored.',
            ]);

        $this->assertSame(0, DocumentAttachment::count());
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_database_failure_returns_safe_json_and_removes_new_file(): void
    {
        [$documentId, $user] = $this->createDocumentAndUser();
        Sanctum::actingAs($user);
        config(['app.debug' => true]);

        $eventName = 'eloquent.creating: '.DocumentAttachment::class;
        Event::listen($eventName, function (): void {
            throw new RuntimeException(
                'SQL bindings sensitive-report.pdf document_attachments/private credentials stack trace'
            );
        });

        $response = $this->postJson(
            "/api/documents/{$documentId}/attachments",
            ['file' => $this->validPdf()]
        );

        Event::forget($eventName);

        $response->assertStatus(500)
            ->assertExactJson([
                'message' => 'Attachment could not be stored.',
            ]);

        foreach ([
            'RuntimeException',
            'trace',
            'file',
            'line',
            'SQL',
            'bindings',
            'report.pdf',
            'document_attachments',
            'private',
            'credentials',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString(
                $sensitiveValue,
                $response->getContent()
            );
        }

        $this->assertSame(0, DocumentAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_successful_delete_removes_file_and_database_row(): void
    {
        [$documentId, $user] = $this->createDocumentAndUser();
        $attachment = $this->createAttachment($documentId, $user);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/attachments/{$attachment->id}")
            ->assertOk()
            ->assertExactJson([
                'message' => 'Attachment deleted successfully.',
            ]);

        $this->assertDatabaseMissing('document_attachments', [
            'id' => $attachment->id,
        ]);
        Storage::disk('local')->assertMissing($attachment->file_path);
    }

    public function test_failed_filesystem_deletion_keeps_row_and_returns_safe_error(): void
    {
        [$documentId, $user] = $this->createDocumentAndUser();
        $attachment = $this->createAttachment($documentId, $user, fileExists: false);
        Sanctum::actingAs($user);
        Log::spy();

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->with($attachment->file_path)->andReturn(true);
        $disk->shouldReceive('delete')->once()->with($attachment->file_path)->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->once()->andReturn($disk);

        $this->deleteJson("/api/attachments/{$attachment->id}")
            ->assertStatus(500)
            ->assertExactJson([
                'message' => 'Attachment could not be deleted.',
            ]);

        $this->assertDatabaseHas('document_attachments', [
            'id' => $attachment->id,
        ]);
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_already_missing_file_still_allows_database_row_deletion(): void
    {
        [$documentId, $user] = $this->createDocumentAndUser();
        $attachment = $this->createAttachment($documentId, $user, fileExists: false);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/attachments/{$attachment->id}")
            ->assertOk()
            ->assertExactJson([
                'message' => 'Attachment deleted successfully.',
            ]);

        $this->assertDatabaseMissing('document_attachments', [
            'id' => $attachment->id,
        ]);
    }

    public function test_validation_and_forbidden_uploads_create_no_file_or_row(): void
    {
        [$documentId, $user] = $this->createDocumentAndUser();
        Sanctum::actingAs($user);

        $this->postJson(
            "/api/documents/{$documentId}/attachments",
            ['file' => UploadedFile::fake()->create('blocked.exe', 1)]
        )->assertUnprocessable();

        $unrelatedUser = $this->createUser('unrelated@example.test', 99);
        Sanctum::actingAs($unrelatedUser);

        $this->postJson(
            "/api/documents/{$documentId}/attachments",
            ['file' => $this->validPdf()]
        )->assertForbidden();

        $this->assertSame(0, DocumentAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function createDocumentAndUser(): array
    {
        $documentId = DB::table('documents')->insertGetId([
            'origin_office_id' => 10,
            'current_office_id' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$documentId, $this->createUser('user@example.test', 20)];
    }

    private function createUser(string $email, int $officeId): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'Office User']);

        return User::query()->create([
            'name' => 'Office User',
            'email' => $email,
            'password' => Hash::make('test-password'),
            'role_id' => $role->id,
            'office_id' => $officeId,
        ]);
    }

    private function createAttachment(
        int $documentId,
        User $user,
        bool $fileExists = true
    ): DocumentAttachment {
        $path = "document_attachments/{$documentId}/existing.pdf";

        if ($fileExists) {
            Storage::disk('local')->put($path, 'existing attachment');
        }

        return DocumentAttachment::query()->create([
            'document_id' => $documentId,
            'original_filename' => 'report.pdf',
            'stored_filename' => 'existing.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 19,
            'uploaded_by' => $user->id,
        ]);
    }

    private function validPdf(): UploadedFile
    {
        return UploadedFile::fake()->create(
            'report.pdf',
            1,
            'application/pdf'
        );
    }
}
