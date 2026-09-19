<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentQrCode;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Process12CInjectionSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'authentication.login_max_attempts' => 20,
            'authentication.login_decay_seconds' => 60,
            'authentication.token_lifetime_minutes' => 480,
            'authentication.token_name' => 'doctrack-smoke',
            'public_access.rate_limits.tracking.max_attempts' => 20,
            'public_access.rate_limits.tracking.decay_seconds' => 60,
            'public_access.rate_limits.qr.max_attempts' => 20,
            'public_access.rate_limits.qr.decay_seconds' => 60,
            'reporting.timezone' => 'Asia/Manila',
        ]);

        $this->schema();
        $this->fixtures();
    }

    protected function tearDown(): void
    {
        RateLimiter::clear(hash('sha256', 'tracking'."\0".'ipv4:'.inet_pton('127.0.0.1')));
        RateLimiter::clear(hash('sha256', 'qr'."\0".'ipv4:'.inet_pton('127.0.0.1')));

        foreach ([
            'personal_access_tokens', 'audit_logs', 'document_qr_codes',
            'document_processing_logs', 'document_routes', 'documents',
            'confidentiality_levels', 'priorities', 'document_statuses',
            'document_types', 'users', 'offices', 'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_injection_shaped_login_email_does_not_authenticate_or_leak_errors(): void
    {
        $before = $this->snapshot();

        $response = $this->postJson('/api/login', [
            'email' => "admin@example.test' OR '1'='1",
            'password' => 'wrong-password',
        ]);

        $this->assertContains($response->status(), [401, 422]);
        $this->assertSafeResponse($response);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_document_search_injection_payload_is_bound_and_read_only(): void
    {
        Sanctum::actingAs($this->viewer());
        $before = $this->snapshot();

        $this->getJson('/api/documents?search='.rawurlencode("%' OR 1=1 --"))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_public_tracking_and_qr_injection_payloads_return_safe_not_found(): void
    {
        $before = $this->snapshot();

        $this->getJson('/api/track/'.rawurlencode("DOC-2026' OR 1=1 --"))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Document tracking number not found.']);

        $this->getJson('/api/q/'.rawurlencode("ABCDE-2345678' OR 1=1 --"))
            ->assertNotFound()
            ->assertExactJson([
                'state' => 'invalid',
                'message' => 'The QR code is invalid or does not exist.',
            ]);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_dashboard_and_audit_filters_reject_injection_payloads_safely(): void
    {
        Sanctum::actingAs($this->admin());
        $before = $this->snapshot();

        $this->getJson('/api/dashboard/summary?month='.rawurlencode("2026-09' OR 1=1 --"))
            ->assertUnprocessable();

        $this->getJson('/api/audit-logs?module='.rawurlencode("documents' OR 1=1 --"))
            ->assertUnprocessable();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_master_data_identifier_injection_is_not_treated_as_an_id(): void
    {
        Sanctum::actingAs($this->admin());
        $before = $this->snapshot();

        $this->getJson('/api/offices/'.rawurlencode("1 OR 1=1"))
            ->assertNotFound();
        $this->getJson('/api/document-types/'.rawurlencode("1 OR 1=1"))
            ->assertNotFound();

        $this->assertSame($before, $this->snapshot());
    }

    private function admin(): User
    {
        return User::query()->where('email', 'admin@example.test')->firstOrFail();
    }

    private function viewer(): User
    {
        return User::query()->where('email', 'viewer@example.test')->firstOrFail();
    }

    private function assertSafeResponse($response): void
    {
        $serialized = strtolower($response->getContent().json_encode($response->headers->all()));

        foreach (['sqlstate', 'syntax error', 'stack trace', 'exception', 'select *', 'password_hash'] as $marker) {
            $this->assertStringNotContainsString($marker, $serialized);
        }
    }

    private function snapshot(): array
    {
        $snapshot = [];

        foreach ([
            'users', 'personal_access_tokens', 'audit_logs', 'documents',
            'document_routes', 'document_qr_codes', 'document_processing_logs',
            'offices', 'document_types',
        ] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(function ($row) use ($table): array {
                    $values = (array) $row;

                    if ($table === 'personal_access_tokens') {
                        unset($values['token']);
                    }

                    if ($table === 'users') {
                        unset($values['password'], $values['remember_token']);
                    }

                    ksort($values);

                    return $values;
                })
                ->all();
        }

        return $snapshot;
    }

    private function fixtures(): void
    {
        $adminRole = Role::query()->create(['name' => 'Administrator']);
        $viewerRole = Role::query()->create(['name' => 'Viewer']);
        $office = Office::query()->create([
            'office_name' => 'Records Office',
            'office_code' => 'REC',
        ]);
        $typeId = DB::table('document_types')->insertGetId(['type_name' => 'Memo', 'created_at' => now(), 'updated_at' => now()]);
        $statusId = DB::table('document_statuses')->insertGetId(['status_name' => 'Received', 'created_at' => now(), 'updated_at' => now()]);
        $priorityId = DB::table('priorities')->insertGetId(['priority_name' => 'Normal', 'created_at' => now(), 'updated_at' => now()]);
        $confidentialityId = DB::table('confidentiality_levels')->insertGetId(['level_name' => 'Public', 'created_at' => now(), 'updated_at' => now()]);

        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('test-password'),
            'role_id' => $adminRole->id,
            'office_id' => $office->id,
        ]);
        User::query()->create([
            'name' => 'Viewer',
            'email' => 'viewer@example.test',
            'password' => Hash::make('test-password'),
            'role_id' => $viewerRole->id,
            'office_id' => $office->id,
        ]);

        $document = Document::query()->create([
            'tracking_no' => 'DOC-20260919000000001',
            'title' => 'Ordinary Document',
            'description' => 'Public details',
            'document_type_id' => $typeId,
            'status_id' => $statusId,
            'priority_id' => $priorityId,
            'confidentiality_level_id' => $confidentialityId,
            'origin_office_id' => $office->id,
            'current_office_id' => $office->id,
            'document_date' => '2026-09-19',
        ]);

        DocumentQrCode::query()->create([
            'qr_token' => 'ABCDE-2345678',
            'status' => 'registered',
            'document_id' => $document->id,
            'generated_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'module' => 'documents',
            'action' => 'created',
            'record_id' => $document->id,
            'description' => 'Safe audit record.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function schema(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('offices', function (Blueprint $table): void {
            $table->id();
            $table->string('office_name', 150);
            $table->string('office_code', 20)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
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
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
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
        Schema::create('document_types', function (Blueprint $table): void {
            $table->id();
            $table->string('type_name', 100)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('document_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('status_name')->unique();
            $table->timestamps();
        });
        Schema::create('priorities', function (Blueprint $table): void {
            $table->id();
            $table->string('priority_name')->unique();
            $table->timestamps();
        });
        Schema::create('confidentiality_levels', function (Blueprint $table): void {
            $table->id();
            $table->string('level_name')->unique();
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('tracking_no', 50)->unique();
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
            $table->string('status')->default('pending');
            $table->timestamps();
        });
        Schema::create('document_routes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('from_office_id');
            $table->unsignedBigInteger('to_office_id');
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
        Schema::create('document_qr_codes', function (Blueprint $table): void {
            $table->id();
            $table->char('qr_token', 36)->unique();
            $table->string('status', 20)->default('unused');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('document_processing_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('office_id')->nullable();
            $table->string('event_type', 50);
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module');
            $table->string('action');
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('description')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }
}
