<?php

namespace Tests\Feature;

use App\Models\DocumentAttachment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentDetailAttachmentSecurityTest extends TestCase
{
    private int $userSequence = 0;

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
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->string('office_name');
            $table->string('office_code')->unique();
            $table->timestamps();
        });

        foreach ([
            'document_types' => 'type_name',
            'document_statuses' => 'status_name',
            'priorities' => 'priority_name',
            'confidentiality_levels' => 'level_name',
        ] as $tableName => $nameColumn) {
            Schema::create($tableName, function (Blueprint $table) use ($nameColumn) {
                $table->id();
                $table->string($nameColumn);
                $table->timestamps();
            });
        }

        Schema::create('processing_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_code')->nullable();
            $table->string('action_name')->nullable();
            $table->timestamps();
        });

        Schema::create('route_actions', function (Blueprint $table) {
            $table->id();
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

        Schema::create('document_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('from_office_id');
            $table->unsignedBigInteger('to_office_id');
            $table->unsignedBigInteger('forwarded_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('action_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('document_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('document_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('qr_token');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('status');
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
        foreach ([
            'document_attachments',
            'document_qr_codes',
            'document_comments',
            'document_routes',
            'documents',
            'route_actions',
            'processing_actions',
            'confidentiality_levels',
            'priorities',
            'document_statuses',
            'document_types',
            'offices',
            'users',
            'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_current_and_origin_offices_use_dedicated_safe_attachment_endpoint(): void
    {
        [$documentId, $originOffice, $currentOffice] =
            $this->createDocumentAndAttachment();

        foreach ([$originOffice, $currentOffice] as $officeId) {
            Sanctum::actingAs($this->createUser('Viewer', $officeId));

            $this->getJson("/api/documents/{$documentId}")
                ->assertOk()
                ->assertJsonMissingPath('attachments')
                ->assertJsonMissingPath('stored_filename')
                ->assertJsonMissingPath('file_path');

            $this->getJson("/api/documents/{$documentId}/attachments")
                ->assertOk()
                ->assertJsonCount(1)
                ->assertJsonMissingPath('0.stored_filename')
                ->assertJsonMissingPath('0.file_path');
        }
    }

    public function test_unrelated_office_detail_cannot_bypass_attachment_authorization(): void
    {
        [$documentId] = $this->createDocumentAndAttachment();
        $unrelatedOffice = $this->createOffice('UNRELATED');
        Sanctum::actingAs($this->createUser('Viewer', $unrelatedOffice));

        $this->getJson("/api/documents/{$documentId}")
            ->assertOk()
            ->assertJsonMissingPath('attachments')
            ->assertJsonMissingPath('stored_filename')
            ->assertJsonMissingPath('file_path');

        $this->getJson("/api/documents/{$documentId}/attachments")
            ->assertForbidden();
    }

    private function createDocumentAndAttachment(): array
    {
        $originOffice = $this->createOffice('ORIGIN');
        $currentOffice = $this->createOffice('CURRENT');
        $documentId = DB::table('documents')->insertGetId([
            'tracking_no' => 'DOC-DETAIL-1',
            'title' => 'Attachment detail security',
            'origin_office_id' => $originOffice,
            'current_office_id' => $currentOffice,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $uploader = $this->createUser('Office User', $currentOffice);

        DocumentAttachment::create([
            'document_id' => $documentId,
            'original_filename' => 'safe-name.pdf',
            'stored_filename' => 'private-name.pdf',
            'file_path' => "document_attachments/{$documentId}/private-name.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 123,
            'uploaded_by' => $uploader->id,
        ]);

        return [$documentId, $originOffice, $currentOffice];
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

    private function createUser(string $roleName, int $officeId): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $this->userSequence++;

        return User::create([
            'name' => $roleName.' User',
            'email' => "detail{$this->userSequence}@example.test",
            'password' => 'test-password',
            'role_id' => $role->id,
            'office_id' => $officeId,
        ]);
    }
}
