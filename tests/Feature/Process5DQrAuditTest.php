<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DocumentQrCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Process5DQrAuditTest extends TestCase
{
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
            $table->unsignedBigInteger('office_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
        Schema::create('document_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('qr_token')->unique();
            $table->string('status')->default('unused');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });
        $this->createAuditTable();
    }

    protected function tearDown(): void
    {
        foreach (['audit_logs', 'document_qr_codes', 'documents', 'users', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_quantity_creates_matching_safe_audits(): void
    {
        $user = $this->user('Records Officer');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/qr-codes', ['quantity' => 3])
            ->assertCreated()
            ->assertJsonPath('quantity', 3);

        $ids = collect($response->json('qr_codes'))->pluck('id')->sort()->values()->all();
        $audits = AuditLog::where('module', AuditLog::MODULE_QR_CODES)
            ->where('action', AuditLog::ACTION_GENERATED)
            ->orderBy('record_id')
            ->get();

        $this->assertSame(3, DocumentQrCode::count());
        $this->assertCount(3, $audits);
        $this->assertSame($ids, $audits->pluck('record_id')->all());
        $this->assertSame([$user->id], $audits->pluck('user_id')->unique()->values()->all());

        foreach ($audits as $audit) {
            $this->assertSame('QR code generated successfully.', $audit->description);
            foreach ($response->json('qr_codes') as $qrCode) {
                $this->assertStringNotContainsString($qrCode['qr_token'], $audit->description);
            }
            foreach ($response->json('scan_paths') as $scanPath) {
                $this->assertStringNotContainsString($scanPath, $audit->description);
            }
        }
    }

    public function test_invalid_unauthenticated_and_unauthorized_generation_create_nothing(): void
    {
        $this->postJson('/api/qr-codes', ['quantity' => 1])->assertUnauthorized();

        Sanctum::actingAs($this->user('Viewer'));
        $this->postJson('/api/qr-codes', ['quantity' => 1])->assertForbidden();

        Sanctum::actingAs($this->user('Records Officer', 'records@example.test'));
        $this->postJson('/api/qr-codes', ['quantity' => 0])->assertUnprocessable();

        $this->assertSame(0, DocumentQrCode::count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_void_creates_one_audit_and_conflicts_create_none(): void
    {
        $user = $this->user('Records Officer');
        Sanctum::actingAs($user);
        $unused = $this->qr($user, 'UNUSED-TOKEN', 'unused');

        $this->postJson("/api/qr-codes/{$unused->id}/void")->assertOk();
        $this->assertSame('void', $unused->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_QR_CODES,
            'action' => AuditLog::ACTION_VOIDED,
            'record_id' => $unused->id,
            'user_id' => $user->id,
            'description' => 'QR code voided successfully.',
        ]);
        $this->assertSame(1, AuditLog::count());

        $void = $this->qr($user, 'VOID-TOKEN', 'void');
        $registered = $this->qr($user, 'REGISTERED-TOKEN', 'registered');
        $this->postJson("/api/qr-codes/{$void->id}/void")->assertConflict();
        $this->postJson("/api/qr-codes/{$registered->id}/void")->assertConflict();
        $this->assertSame(1, AuditLog::count());
    }

    public function test_forbidden_void_does_not_mutate_or_audit(): void
    {
        $owner = $this->user('Records Officer');
        $qr = $this->qr($owner, 'FORBIDDEN-TOKEN', 'unused');
        Sanctum::actingAs($this->user('Viewer', 'viewer@example.test'));

        $this->postJson("/api/qr-codes/{$qr->id}/void")->assertForbidden();

        $this->assertSame('unused', $qr->fresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_audit_failure_does_not_break_generation_or_voiding(): void
    {
        $user = $this->user('Records Officer');
        Sanctum::actingAs($user);
        Log::spy();
        Schema::drop('audit_logs');

        $generated = $this->postJson('/api/qr-codes', ['quantity' => 1])
            ->assertCreated()
            ->json('qr_codes.0.id');
        $this->postJson("/api/qr-codes/{$generated}/void")->assertOk();

        $this->assertSame('void', DocumentQrCode::findOrFail($generated)->status);
        Log::shouldHaveReceived('warning')->twice();
    }

    private function user(string $roleName, string $email = 'user@example.test'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        return User::create([
            'name' => $roleName,
            'email' => $email,
            'password' => Hash::make('test-password'),
            'role_id' => $role->id,
            'office_id' => 1,
        ]);
    }

    private function qr(User $user, string $token, string $status): DocumentQrCode
    {
        return DocumentQrCode::create([
            'qr_token' => $token,
            'status' => $status,
            'generated_by' => $user->id,
            'generated_at' => now(),
        ]);
    }

    private function createAuditTable(): void
    {
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
}
