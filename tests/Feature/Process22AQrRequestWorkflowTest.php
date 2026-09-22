<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DocumentQrCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Process22AQrRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $officeA;
    private int $officeB;
    private User $admin;
    private User $recordsA;
    private User $officeAUser;
    private User $recordsB;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Administrator', 'Records Officer', 'Office User', 'Viewer'] as $role) {
            Role::create(['name' => $role]);
        }

        $this->officeA = DB::table('offices')->insertGetId([
            'office_name' => 'Office A',
            'office_code' => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->officeB = DB::table('offices')->insertGetId([
            'office_name' => 'Office B',
            'office_code' => 'B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = $this->user('Administrator', 'admin@example.test', $this->officeA);
        $this->recordsA = $this->user('Records Officer', 'records-a@example.test', $this->officeA);
        $this->officeAUser = $this->user('Office User', 'office-a@example.test', $this->officeA);
        $this->recordsB = $this->user('Records Officer', 'records-b@example.test', $this->officeB);
        $this->viewer = $this->user('Viewer', 'viewer@example.test', $this->officeB);

        DB::table('document_types')->insert([
            'type_name' => 'Memorandum',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('document_statuses')->insert([
            'status_name' => 'Pending',
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
        DB::table('processing_actions')->insert([
            'action_code' => 'REGISTERED',
            'action_name' => 'Registered',
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_qr_request_approval_scoping_registration_and_void_rules(): void
    {
        Sanctum::actingAs($this->recordsA);

        $requestResponse = $this->postJson('/api/qr-code-requests', [
            'quantity' => 2,
            'purpose' => 'Front desk intake labels.',
        ])
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.quantity', 2);

        $requestId = $requestResponse->json('request.id');
        $this->assertDatabaseHas('qr_code_requests', [
            'id' => $requestId,
            'requested_by_user_id' => $this->recordsA->id,
            'requested_office_id' => $this->officeA,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_QR_CODE_REQUESTS,
            'action' => AuditLog::ACTION_REQUESTED,
            'record_id' => $requestId,
            'user_id' => $this->recordsA->id,
        ]);

        Sanctum::actingAs($this->recordsB);
        $this->getJson('/api/qr-code-requests')
            ->assertOk()
            ->assertJsonPath('data', []);

        Sanctum::actingAs($this->officeAUser);
        $this->getJson('/api/qr-code-requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $requestId);

        Sanctum::actingAs($this->recordsA);
        $this->postJson("/api/qr-code-requests/{$requestId}/approve")
            ->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/qr-code-requests/{$requestId}/approve", [
            'review_note' => 'Approved for office A.',
        ])
            ->assertOk()
            ->assertJsonPath('request.status', 'approved')
            ->assertJsonCount(2, 'request.qr_codes');

        $qrRows = DocumentQrCode::query()
            ->where('qr_code_request_id', $requestId)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $qrRows);
        foreach ($qrRows as $qrRow) {
            $this->assertSame('unused', $qrRow->status);
            $this->assertSame($this->officeA, $qrRow->assigned_office_id);
            $this->assertSame($this->admin->id, $qrRow->generated_by);
            $this->assertNull($qrRow->document_id);
        }
        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_QR_CODE_REQUESTS,
            'action' => AuditLog::ACTION_APPROVED,
            'record_id' => $requestId,
            'user_id' => $this->admin->id,
        ]);
        $this->assertSame(
            2,
            AuditLog::where('module', AuditLog::MODULE_QR_CODES)
                ->where('action', AuditLog::ACTION_GENERATED)
                ->count()
        );

        $this->postJson("/api/qr-code-requests/{$requestId}/approve")
            ->assertConflict();

        Sanctum::actingAs($this->recordsB);
        $this->getJson('/api/qr-code-requests')
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson('/api/qr-codes?status=unused')
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson('/api/qr-codes/'.$qrRows[0]->id)
            ->assertNotFound();

        $documentPayload = $this->documentPayload($this->officeB, $qrRows[0]->qr_token);
        $this->postJson('/api/documents', $documentPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('qr_token');
        $this->assertSame('unused', $qrRows[0]->fresh()->status);

        Sanctum::actingAs($this->recordsA);
        $this->getJson('/api/qr-codes?status=unused')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $registered = $this->postJson(
            '/api/documents',
            $this->documentPayload($this->officeA, $qrRows[0]->qr_token)
        )
            ->assertCreated()
            ->assertJsonPath('qr_linked', true);

        $documentId = $registered->json('document.id');
        $this->assertSame($documentId, $qrRows[0]->fresh()->document_id);
        $this->assertSame('registered', $qrRows[0]->fresh()->status);

        $this->getJson('/api/q/'.$qrRows[0]->qr_token)
            ->assertOk()
            ->assertJsonPath('state', 'registered');

        $this->postJson('/api/qr-codes/'.$qrRows[1]->id.'/void')
            ->assertForbidden();
        $this->assertSame('unused', $qrRows[1]->fresh()->status);

        Sanctum::actingAs($this->viewer);
        $this->postJson('/api/qr-code-requests', ['quantity' => 1])
            ->assertForbidden();
        $this->postJson('/api/qr-codes/'.$qrRows[1]->id.'/void')
            ->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/qr-codes/'.$qrRows[1]->id.'/void')
            ->assertOk();
        $this->assertSame('void', $qrRows[1]->fresh()->status);
    }

    public function test_admin_rejects_pending_request_without_generating_qr_codes(): void
    {
        Sanctum::actingAs($this->recordsA);
        $requestId = $this->postJson('/api/qr-code-requests', [
            'quantity' => 1,
        ])
            ->assertCreated()
            ->json('request.id');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/qr-code-requests/{$requestId}/reject", [
            'review_note' => 'Use existing labels first.',
        ])
            ->assertOk()
            ->assertJsonPath('request.status', 'rejected')
            ->assertJsonPath('request.review_note', 'Use existing labels first.');

        $this->assertSame(0, DocumentQrCode::count());
        $this->assertDatabaseHas('audit_logs', [
            'module' => AuditLog::MODULE_QR_CODE_REQUESTS,
            'action' => AuditLog::ACTION_REJECTED,
            'record_id' => $requestId,
            'user_id' => $this->admin->id,
        ]);

        $this->postJson("/api/qr-code-requests/{$requestId}/reject")
            ->assertConflict();
    }

    private function user(string $roleName, string $email, int $officeId): User
    {
        return User::create([
            'name' => $roleName.' '.$email,
            'email' => $email,
            'password' => Hash::make('test-password'),
            'role_id' => Role::where('name', $roleName)->value('id'),
            'office_id' => $officeId,
        ]);
    }

    private function documentPayload(int $officeId, string $qrToken): array
    {
        return [
            'title' => 'QR request workflow document',
            'description' => 'Synthetic QR workflow test.',
            'document_type_id' => DB::table('document_types')->value('id'),
            'priority_id' => DB::table('priorities')->value('id'),
            'confidentiality_level_id' => DB::table('confidentiality_levels')->value('id'),
            'origin_office_id' => $officeId,
            'document_date' => '2026-09-22',
            'qr_token' => $qrToken,
        ];
    }
}
