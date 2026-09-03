<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DocumentQrCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            $table->string('tracking_no')->unique();
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
            $table->timestamps();
        });
        Schema::create('document_routes', function (Blueprint $table) {
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
        Schema::create('document_processing_logs', function (Blueprint $table) {
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
        $this->createAuditTable();
        DB::table('documents')->insert([
            'tracking_no' => 'QR-SNAPSHOT-DOC',
            'title' => 'QR snapshot fixture',
            'description' => 'Non-sensitive mutation sentinel',
            'status' => 'pending',
            'document_type_id' => 101,
            'status_id' => 102,
            'priority_id' => 103,
            'confidentiality_level_id' => 104,
            'origin_office_id' => 105,
            'current_office_id' => 106,
            'current_action_id' => 107,
            'processing_note' => 'Fixture note sentinel',
            'current_action_updated_by' => 108,
            'current_action_updated_at' => '2026-09-01 08:00:00',
            'created_by' => 109,
            'document_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'created_at' => '2026-09-01 07:00:00',
            'updated_at' => '2026-09-01 07:30:00',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([
            'audit_logs', 'personal_access_tokens', 'document_attachments', 'document_qr_codes',
            'document_processing_logs', 'document_routes', 'documents',
            'users', 'roles',
        ] as $table) {
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

    public function test_inventory_is_manage_protected_and_rejected_reads_are_inert(): void
    {
        $owner = $this->user('Records Officer');
        $this->qr($owner, 'INVENTORY-PROTECTED', 'unused');

        $before = $this->completeSnapshot();
        $this->getJson('/api/qr-codes/inventory')->assertUnauthorized();
        $this->assertSame($before, $this->completeSnapshot());

        foreach (['Viewer', 'Unexpected Role'] as $role) {
            Sanctum::actingAs($this->user($role, strtolower(str_replace(' ', '-', $role)).'@example.test'));
            $before = $this->completeSnapshot();
            $this->getJson('/api/qr-codes/inventory')->assertForbidden();
            $this->assertSame($before, $this->completeSnapshot());
        }
    }

    public function test_empty_inventory_has_exact_safe_read_only_contract(): void
    {
        Sanctum::actingAs($this->user('Administrator'));
        $before = $this->completeSnapshot();

        $response = $this->getJson('/api/qr-codes/inventory?status=unused&per_page=10&page=1')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 10,
                    'total' => 0,
                    'from' => null,
                    'to' => null,
                ],
            ]);

        $this->assertSame($before, $this->completeSnapshot());
        foreach (['qr_token', 'document_id', 'generated_by', 'email', 'title', 'links'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($response->getContent()));
        }
    }

    public function test_inventory_is_token_free_deterministic_paginated_and_read_only(): void
    {
        $user = $this->user('Administrator');
        $ids = [];

        for ($index = 1; $index <= 12; $index++) {
            $ids[] = DocumentQrCode::create([
                'qr_token' => 'SAFE-INVENTORY-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'status' => $index === 1 ? 'registered' : 'unused',
                'document_id' => $index === 1 ? 1 : null,
                'generated_by' => $user->id,
                'generated_at' => $index <= 2
                    ? '2026-09-03 03:16:15'
                    : '2026-09-03 03:13:05',
            ])->id;
        }

        Sanctum::actingAs($user);
        $before = $this->completeSnapshot();
        $response = $this->getJson('/api/qr-codes/inventory?status=unused&per_page=10&page=1')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'status', 'issued_at', 'linked']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            ])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 11);
        $this->assertSame(['data', 'meta'], array_keys($response->json()));
        $this->assertSame(
            ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            array_keys($response->json('meta'))
        );
        $this->assertSame($before, $this->completeSnapshot());

        $data = $response->json('data');
        $expectedIds = [
            $ids[1],
            ...array_reverse(array_slice($ids, 2)),
        ];
        $this->assertSame(array_slice($expectedIds, 0, 10), array_column($data, 'id'));
        foreach ($data as $item) {
            $this->assertSame(['id', 'status', 'issued_at', 'linked'], array_keys($item));
            $this->assertSame('unused', $item['status']);
            $this->assertFalse($item['linked']);
        }

        $serialized = strtolower($response->getContent());
        foreach (['qr_token', 'safe-inventory', 'generated_by', 'document_id', 'email', 'title', 'description'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }

        $beforePage = $this->completeSnapshot();
        $this->getJson('/api/qr-codes/inventory?status=unused&per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expectedIds[10]);
        $this->assertSame($beforePage, $this->completeSnapshot());

        $beforeRegistered = $this->completeSnapshot();
        $this->getJson('/api/qr-codes/inventory?status=registered&per_page=25&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ids[0])
            ->assertJsonPath('data.0.status', 'registered')
            ->assertJsonPath('data.0.linked', true)
            ->assertJsonPath('meta.per_page', 25);
        $this->assertSame($beforeRegistered, $this->completeSnapshot());
    }

    public function test_inventory_rejects_invalid_and_unknown_parameters_without_mutation(): void
    {
        Sanctum::actingAs($this->user('Records Officer'));

        foreach ([
            'page=0', 'page=1.5', 'per_page=11', 'status=quarantined',
            'status[]=unused', 'unknown=value', 'page=999',
        ] as $query) {
            $before = $this->completeSnapshot();
            $this->getJson('/api/qr-codes/inventory?'.$query)->assertUnprocessable();
            $this->assertSame($before, $this->completeSnapshot());
        }
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

        $beforeReplay = $this->completeSnapshot();
        $this->postJson("/api/qr-codes/{$unused->id}/void")
            ->assertConflict();
        $this->assertSame($beforeReplay, $this->completeSnapshot());

        $void = $this->qr($user, 'VOID-TOKEN', 'void');
        $registered = $this->qr($user, 'REGISTERED-TOKEN', 'registered');

        $beforeVoid = $this->completeSnapshot();
        $this->postJson("/api/qr-codes/{$void->id}/void")->assertConflict();
        $this->assertSame($beforeVoid, $this->completeSnapshot());

        $beforeRegistered = $this->completeSnapshot();
        $this->postJson("/api/qr-codes/{$registered->id}/void")->assertConflict();
        $this->assertSame($beforeRegistered, $this->completeSnapshot());
        $this->assertSame(1, AuditLog::count());
    }

    public function test_forbidden_void_does_not_mutate_or_audit(): void
    {
        $owner = $this->user('Records Officer');
        $qr = $this->qr($owner, 'FORBIDDEN-TOKEN', 'unused');
        Sanctum::actingAs($this->user('Viewer', 'viewer@example.test'));
        $before = $this->completeSnapshot();

        $this->postJson("/api/qr-codes/{$qr->id}/void")->assertForbidden();

        $this->assertSame($before, $this->completeSnapshot());
    }

    public function test_unauthenticated_void_does_not_mutate_or_audit(): void
    {
        $owner = $this->user('Records Officer');
        $qr = $this->qr($owner, 'UNAUTHENTICATED-VOID-TOKEN', 'unused');
        $before = $this->completeSnapshot();

        $this->postJson("/api/qr-codes/{$qr->id}/void")
            ->assertUnauthorized();

        $this->assertSame($before, $this->completeSnapshot());
    }

    public function test_void_reloads_stale_qr_state_before_transition(): void
    {
        $user = $this->user('Records Officer');
        $qr = $this->qr($user, 'STALE-VOID-TOKEN', 'unused');
        $staleQr = $qr->fresh();
        DocumentQrCode::whereKey($qr->id)->update(['status' => 'registered']);
        Sanctum::actingAs($user);
        $expectedAfterExternalChange = $this->completeSnapshot();

        $this->postJson("/api/qr-codes/{$staleQr->id}/void")
            ->assertConflict();

        $this->assertSame(
            $expectedAfterExternalChange,
            $this->completeSnapshot()
        );
    }

    public function test_linked_unused_qr_is_not_voidable(): void
    {
        $user = $this->user('Records Officer');
        $qr = $this->qr($user, 'LINKED-UNUSED-TOKEN', 'unused');
        $qr->update(['document_id' => 1]);
        Sanctum::actingAs($user);
        $before = $this->completeSnapshot();

        $this->postJson("/api/qr-codes/{$qr->id}/void")->assertConflict();

        $this->assertSame($before, $this->completeSnapshot());
    }

    public function test_unexpected_qr_state_is_rejected_without_any_side_effect(): void
    {
        $user = $this->user('Records Officer');
        $qr = $this->qr($user, 'UNEXPECTED-STATE-TOKEN', 'quarantined');
        Sanctum::actingAs($user);
        $before = $this->completeSnapshot();

        $response = $this->postJson("/api/qr-codes/{$qr->id}/void")
            ->assertConflict();

        $this->assertSame($before, $this->completeSnapshot());
        $content = strtolower($response->getContent());
        foreach ([
            strtolower($qr->qr_token), 'sql', 'exception', 'transaction',
            'lock', 'file_path', 'stored_filename',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
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

    private function completeSnapshot(): array
    {
        $audits = $this->normalizedRows('audit_logs', [
            'id', 'user_id', 'module', 'action', 'record_id', 'description',
            'ip_address', 'user_agent', 'created_at', 'updated_at',
        ], ['id'], ['user_id', 'record_id']);

        return [
            'documents' => $this->normalizedRows('documents', [
                'id', 'tracking_no', 'title', 'description', 'status',
                'document_type_id', 'status_id', 'priority_id',
                'confidentiality_level_id', 'origin_office_id',
                'current_office_id', 'current_action_id', 'processing_note',
                'current_action_updated_by', 'current_action_updated_at',
                'created_by', 'document_date', 'due_date', 'created_at',
                'updated_at',
            ], ['id'], [
                'document_type_id', 'status_id', 'priority_id',
                'confidentiality_level_id', 'origin_office_id',
                'current_office_id', 'current_action_id',
                'current_action_updated_by', 'created_by',
            ]),
            'routes' => $this->normalizedRows('document_routes', [
                'id', 'document_id', 'from_office_id', 'to_office_id',
                'forwarded_by', 'received_by', 'forwarded_at', 'received_at',
                'status_id', 'action_id', 'remarks', 'created_at', 'updated_at',
            ], [
                'id', 'document_id', 'from_office_id', 'to_office_id',
                'forwarded_by', 'status_id',
            ], ['received_by', 'action_id']),
            'processing' => $this->normalizedRows(
                'document_processing_logs',
                [
                    'id', 'document_id', 'office_id', 'user_id',
                    'processing_action_id', 'document_route_id', 'event_type',
                    'processing_note', 'event_note', 'created_at', 'updated_at',
                ],
                ['id', 'document_id'],
                ['office_id', 'user_id', 'processing_action_id', 'document_route_id']
            ),
            'qr_codes' => $this->normalizedRows('document_qr_codes', [
                'id', 'qr_token', 'status', 'document_id', 'generated_by',
                'generated_at', 'registered_at', 'created_at', 'updated_at',
            ], ['id'], ['document_id', 'generated_by']),
            'attachments' => $this->normalizedRows('document_attachments', [
                'id', 'document_id', 'original_filename', 'stored_filename',
                'file_path', 'mime_type', 'file_size', 'uploaded_by',
                'created_at', 'updated_at',
            ], ['id', 'document_id', 'uploaded_by'], ['file_size']),
            'audits' => $audits,
            'audit_count' => count($audits),
            'tokens' => $this->normalizedRows('personal_access_tokens', [
                'id', 'tokenable_type', 'tokenable_id', 'name', 'abilities',
                'last_used_at', 'expires_at', 'created_at', 'updated_at',
            ], ['id', 'tokenable_id'], [], ['abilities']),
            'token_count' => DB::table('personal_access_tokens')->count(),
        ];
    }

    private function normalizedRows(
        string $table,
        array $columns,
        array $integerColumns = [],
        array $nullableIntegerColumns = [],
        array $jsonColumns = []
    ): array {
        return DB::table($table)->orderBy('id')->get($columns)
            ->map(function ($row) use (
                $columns,
                $integerColumns,
                $nullableIntegerColumns,
                $jsonColumns
            ): array {
                $normalized = [];

                foreach ($columns as $column) {
                    $value = $row->{$column};

                    if (in_array($column, $integerColumns, true)) {
                        $value = (int) $value;
                    } elseif (in_array($column, $nullableIntegerColumns, true)) {
                        $value = $value === null ? null : (int) $value;
                    } elseif (in_array($column, $jsonColumns, true)) {
                        $value = $value === null
                            ? null
                            : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
                    } elseif ($value !== null) {
                        $value = (string) $value;
                    }

                    $normalized[$column] = $value;
                }

                return $normalized;
            })->all();
    }
}
