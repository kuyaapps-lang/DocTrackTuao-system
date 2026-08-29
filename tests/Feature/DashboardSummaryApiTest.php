<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardSummaryApiTest extends TestCase
{
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['reporting.timezone' => 'Asia/Manila']);

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('offices', function (Blueprint $table): void {
            $table->id();
            $table->string('office_name');
            $table->string('office_code')->unique();
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
        Schema::create('document_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('status_name')->unique();
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table): void {
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
        Schema::create('document_routes', function (Blueprint $table): void {
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
        Schema::create('document_processing_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('office_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('processing_action_id')->nullable();
            $table->unsignedBigInteger('document_route_id')->nullable();
            $table->string('event_type')->nullable();
            $table->text('processing_note')->nullable();
            $table->string('event_note', 1000)->nullable();
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
            $table->string('user_agent')->nullable();
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
    }

    protected function tearDown(): void
    {
        foreach ([
            'personal_access_tokens',
            'audit_logs',
            'document_processing_logs',
            'document_routes',
            'documents',
            'document_statuses',
            'users',
            'offices',
            'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_unauthenticated_request_is_rejected_without_mutation(): void
    {
        $this->readOnlyRequest(
            fn (): TestResponse => $this->getJson('/api/dashboard/summary')
        )->assertUnauthorized();
    }

    public function test_role_without_reports_permission_is_forbidden(): void
    {
        Sanctum::actingAs($this->user('Unknown Role'));
        $this->readOnlyRequest(
            fn (): TestResponse => $this->getJson('/api/dashboard/summary')
        )->assertForbidden();
    }

    public function test_administrator_and_records_officer_have_system_scope(): void
    {
        $status = $this->documentStatus('Received');
        $office = $this->office('SYSTEM');
        $this->document($status, $office, $office);

        foreach (['Administrator', 'Records Officer'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->readOnlyRequest(
                fn (): TestResponse =>
                    $this->getJson('/api/dashboard/summary')
            )
                ->assertOk()
                ->assertJsonPath('scope.type', 'system')
                ->assertJsonPath('scope.office', null)
                ->assertJsonPath('summary.total_documents', 1)
                ->assertJsonPath('filters.timezone', 'Asia/Manila');
        }
    }

    public function test_office_user_and_viewer_have_office_scope(): void
    {
        $office = $this->office('SCOPE');

        foreach (['Office User', 'Viewer'] as $role) {
            Sanctum::actingAs($this->user($role, $office));

            $this->readOnlyRequest(
                fn (): TestResponse =>
                    $this->getJson('/api/dashboard/summary')
            )
                ->assertOk()
                ->assertJsonPath('scope.type', 'office')
                ->assertJsonPath('scope.office.id', $office)
                ->assertJsonPath('scope.office.name', 'Office SCOPE');
        }
    }

    public function test_office_scoped_user_without_office_is_forbidden(): void
    {
        foreach (['Office User', 'Viewer'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->readOnlyRequest(
                fn (): TestResponse =>
                    $this->getJson('/api/dashboard/summary')
            )->assertForbidden();
        }
    }

    public function test_deleted_or_invalid_assigned_office_is_forbidden(): void
    {
        $office = $this->office('DELETED');
        $user = $this->user('Office User', $office);
        DB::table('offices')->where('id', $office)->delete();
        Sanctum::actingAs($user);
        $this->readOnlyRequest(
            fn (): TestResponse => $this->getJson('/api/dashboard/summary')
        )->assertForbidden();
    }

    public function test_unexpected_reports_role_never_receives_system_scope(): void
    {
        $role = 'Unexpected Reports Role';
        $office = $this->office('CUSTOM');
        $scopedUser = $this->user($role, $office);
        $missingOfficeUser = $this->user($role);

        Gate::define(
            'reports.view',
            fn (User $user): bool => $user->hasRole($role)
        );

        try {
            Sanctum::actingAs($scopedUser);
            $this->readOnlyRequest(
                fn (): TestResponse =>
                    $this->getJson('/api/dashboard/summary')
            )
                ->assertOk()
                ->assertJsonPath('scope.type', 'office')
                ->assertJsonPath('scope.office.id', $office);

            Sanctum::actingAs($missingOfficeUser);
            $this->readOnlyRequest(
                fn (): TestResponse =>
                    $this->getJson('/api/dashboard/summary')
            )->assertForbidden();
        } finally {
            Gate::define(
                'reports.view',
                fn (User $user): bool =>
                    $user->hasPermission('reports.view')
            );
        }
    }

    public function test_empty_database_has_exact_safe_shape(): void
    {
        Sanctum::actingAs($this->user('Administrator'));

        $this->readOnlyRequest(
            fn (): TestResponse => $this->getJson('/api/dashboard/summary')
        )->assertExactJson([
            'filters' => [
                'month' => null,
                'timezone' => 'Asia/Manila',
            ],
            'scope' => [
                'type' => 'system',
                'office' => null,
            ],
            'summary' => [
                'total_documents' => 0,
                'incoming_movements' => 0,
                'outgoing_movements' => 0,
                'in_transit_documents' => 0,
                'received_documents' => 0,
            ],
            'status_distribution' => [],
            'current_office_distribution' => [],
            'origin_office_distribution' => [],
            'recent_documents' => [],
            'recent_routing_activity' => [],
        ]);
    }

    public function test_office_universe_includes_origin_current_and_history_once(): void
    {
        $scope = $this->office('IN');
        $other = $this->office('OUT');
        $status = $this->documentStatus('Received');
        $origin = $this->document($status, $scope, $other);
        $current = $this->document($status, $other, $scope);
        $history = $this->document($status, $other, $other);
        $overlap = $this->document($status, $scope, $scope);
        $unrelated = $this->document($status, $other, $other);
        $this->route($history, $scope, $other);
        $this->route($history, $other, $scope);
        $this->route($history, $scope, $other);
        $this->route($overlap, $other, $scope);

        Sanctum::actingAs($this->user('Office User', $scope));

        $response = $this->readOnlyRequest(
            fn (): TestResponse => $this->getJson('/api/dashboard/summary')
        )->assertOk();

        $response->assertJsonPath('summary.total_documents', 4);
        $ids = collect($response->json('recent_documents'))->pluck('id');
        $this->assertEqualsCanonicalizing(
            [$origin, $current, $history, $overlap],
            $ids->all()
        );
        $this->assertNotContains($unrelated, $ids->all());
        $this->assertSame(
            4,
            array_sum(array_column(
                $response->json('status_distribution'),
                'count'
            ))
        );
        $this->assertSame(
            4,
            array_sum(array_column(
                $response->json('current_office_distribution'),
                'count'
            ))
        );
        $this->assertSame(
            4,
            array_sum(array_column(
                $response->json('origin_office_distribution'),
                'count'
            ))
        );
    }

    public function test_movements_count_route_events_and_current_state_is_defensive(): void
    {
        $scope = $this->office('MOVE');
        $other = $this->office('OTHER');
        $pendingStatus = $this->documentStatus('Pending');
        $receivedStatus = $this->documentStatus('Received');
        $pendingLookupOnly = $this->document($pendingStatus, $scope, $scope);
        $moving = $this->document($receivedStatus, $scope, $scope);
        $received = $this->document($receivedStatus, $scope, $scope);
        $this->route($moving, $scope, $other, receivedAt: null);
        $this->route($moving, $other, $scope, receivedAt: '2026-08-10 01:00:00');
        $this->route($received, $scope, $other, receivedAt: '2026-08-11 01:00:00');

        Sanctum::actingAs($this->user('Viewer', $scope));

        $response = $this->readOnlyRequest(
            fn (): TestResponse => $this->getJson('/api/dashboard/summary')
        )->assertOk();

        $response
            ->assertJsonPath('summary.incoming_movements', 1)
            ->assertJsonPath('summary.outgoing_movements', 2)
            ->assertJsonPath('summary.in_transit_documents', 1)
            ->assertJsonPath('summary.received_documents', 1);
        $this->assertNotSame($pendingLookupOnly, $moving);
    }

    public function test_distributions_include_unassigned_and_use_current_values(): void
    {
        $office = $this->office('DIST');
        $received = $this->documentStatus('Received');
        $this->document($received, $office, $office);
        $this->document(null, null, null);
        Sanctum::actingAs($this->user('Administrator'));

        $response = $this->readOnlyRequest(
            fn (): TestResponse => $this->getJson('/api/dashboard/summary')
        )->assertOk();

        $this->assertSame([
            ['status' => ['id' => $received, 'name' => 'Received'], 'count' => 1],
            ['status' => ['id' => null, 'name' => 'Unassigned'], 'count' => 1],
        ], $response->json('status_distribution'));
        $this->assertSame('Unassigned', $response->json(
            'current_office_distribution.1.office.name'
        ));
        $this->assertSame('Unassigned', $response->json(
            'origin_office_distribution.1.office.name'
        ));
    }

    public function test_month_is_strict_and_unknown_parameters_are_rejected(): void
    {
        Sanctum::actingAs($this->user('Administrator'));

        foreach (['2026-00', '2026-13', '2026-8', '0000-08', 'not-a-month'] as $month) {
            $this->readOnlyRequest(
                fn (): TestResponse => $this->getJson(
                    '/api/dashboard/summary?month='.$month
                )
            )
                ->assertUnprocessable();
        }

        $this->readOnlyRequest(
            fn (): TestResponse => $this->getJson(
                '/api/dashboard/summary?month%5B0%5D=2026-08'
            )
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');

        foreach (['office', 'role', 'scope', 'sort', 'column', 'group', 'start', 'end'] as $key) {
            $this->readOnlyRequest(
                fn (): TestResponse => $this->getJson(
                    '/api/dashboard/summary?'.$key.'=1'
                )
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors($key);
        }
    }

    public function test_omitted_month_explicitly_uses_all_time_data(): void
    {
        $office = $this->office('ALLTIME');
        $status = $this->documentStatus('Received');
        $first = $this->document(
            $status,
            $office,
            $office,
            '2025-01-15 00:00:00'
        );
        $second = $this->document(
            $status,
            $office,
            $office,
            '2026-08-15 00:00:00'
        );
        $this->route(
            $first,
            $office,
            $office,
            '2025-01-15 01:00:00'
        );
        $this->route(
            $second,
            $office,
            $office,
            '2026-08-15 01:00:00'
        );
        Sanctum::actingAs($this->user('Administrator'));

        $response = $this->readOnlyRequest(
            fn (): TestResponse => $this->getJson('/api/dashboard/summary')
        )
            ->assertOk()
            ->assertJsonPath('filters.month', null)
            ->assertJsonPath('summary.total_documents', 2)
            ->assertJsonPath('summary.incoming_movements', 2)
            ->assertJsonPath('summary.outgoing_movements', 2);

        $this->assertEqualsCanonicalizing(
            [$first, $second],
            collect($response->json('recent_documents'))->pluck('id')->all()
        );
    }

    public function test_invalid_reporting_timezone_returns_safe_500_without_side_effects(): void
    {
        Sanctum::actingAs($this->user('Administrator'));
        config(['reporting.timezone' => 'Invalid/Private-Timezone-Value']);

        foreach ([
            '/api/dashboard/summary',
            '/api/dashboard/summary?month=2026-08',
        ] as $endpoint) {
            $response = $this->readOnlyRequest(
                fn (): TestResponse => $this->getJson($endpoint)
            )
                ->assertStatus(500)
                ->assertExactJson([
                    'message' =>
                        'Dashboard summary is temporarily unavailable.',
                ]);

            $serialized = strtolower($response->getContent());
            foreach ([
                'invalid/private',
                'timezoneexception',
                'stack',
                'trace',
                'dashboardcontroller.php',
                'config',
                'select ',
                'sql',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $serialized
                );
            }
        }
    }

    public function test_manila_month_boundaries_are_compared_in_utc(): void
    {
        $status = $this->documentStatus('Received');
        $office = $this->office('TZ');
        $before = $this->document($status, $office, $office, '2026-07-31 15:59:59');
        $first = $this->document($status, $office, $office, '2026-07-31 16:00:00');
        $last = $this->document($status, $office, $office, '2026-08-31 15:59:59');
        $after = $this->document($status, $office, $office, '2026-08-31 16:00:00');
        $this->route($first, $office, $office, '2026-07-31 16:00:00');
        $this->route($last, $office, $office, '2026-08-31 15:59:59');
        $this->route($after, $office, $office, '2026-08-31 16:00:00');
        Sanctum::actingAs($this->user('Administrator'));

        $response = $this->readOnlyRequest(
            fn (): TestResponse => $this->getJson(
                '/api/dashboard/summary?month=2026-08'
            )
        )
            ->assertOk()
            ->assertJsonPath('summary.total_documents', 2)
            ->assertJsonPath('summary.incoming_movements', 2)
            ->assertJsonPath('summary.outgoing_movements', 2);

        $ids = collect($response->json('recent_documents'))->pluck('id');
        $this->assertEqualsCanonicalizing([$first, $last], $ids->all());
        $this->assertNotContains($before, $ids->all());
        $this->assertNotContains($after, $ids->all());
    }

    public function test_recent_results_are_safe_deterministic_and_limited(): void
    {
        $office = $this->office('SAFE');
        $status = $this->documentStatus('Received');
        $user = $this->user('Administrator');

        foreach (range(1, 12) as $index) {
            $document = $this->document(
                $status,
                $office,
                $office,
                '2026-08-10 00:00:00',
                "Sensitive title {$index}",
                "Sensitive description {$index}"
            );
            $this->route(
                $document,
                $office,
                $office,
                '2026-08-10 01:00:00',
                '2026-08-10 01:00:00',
                "Sensitive remark {$index}"
            );
        }

        Sanctum::actingAs($user);
        $response = $this->readOnlyRequest(
            fn (): TestResponse => $this->getJson('/api/dashboard/summary')
        )->assertOk();

        $documents = $response->json('recent_documents');
        $activity = $response->json('recent_routing_activity');
        $this->assertCount(10, $documents);
        $this->assertCount(10, $activity);
        $this->assertSame(
            collect($documents)->pluck('id')->sortDesc()->values()->all(),
            collect($documents)->pluck('id')->all()
        );
        $this->assertSame('received', $activity[0]['event_type']);
        $this->assertSame(
            ['id', 'tracking_no', 'status', 'created_at'],
            array_keys($documents[0])
        );
        $this->assertSame(
            ['document', 'event_type', 'from_office', 'to_office', 'occurred_at'],
            array_keys($activity[0])
        );

        $serialized = strtolower($response->getContent());
        foreach ([
            'sensitive title',
            'sensitive description',
            'sensitive remark',
            'processing_note',
            'email',
            'attachment',
            'filename',
            'password',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    private function role(string $name): int
    {
        return (int) Role::firstOrCreate(['name' => $name])->id;
    }

    private function user(string $role, ?int $officeId = null): User
    {
        $this->sequence++;

        return User::create([
            'name' => $role.' User',
            'email' => "user{$this->sequence}@example.test",
            'password' => 'not-a-production-credential',
            'role_id' => $this->role($role),
            'office_id' => $officeId,
        ]);
    }

    private function office(string $code): int
    {
        return (int) DB::table('offices')->insertGetId([
            'office_name' => 'Office '.$code,
            'office_code' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function documentStatus(string $name): int
    {
        return (int) DB::table('document_statuses')->insertGetId([
            'status_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function document(
        ?int $statusId,
        ?int $originOfficeId,
        ?int $currentOfficeId,
        string $createdAt = '2026-08-10 00:00:00',
        ?string $title = null,
        ?string $description = null
    ): int {
        $this->sequence++;

        return (int) DB::table('documents')->insertGetId([
            'tracking_no' => 'DOC-TEST-'.$this->sequence,
            'title' => $title ?? 'Dashboard fixture',
            'description' => $description,
            'status_id' => $statusId,
            'origin_office_id' => $originOfficeId,
            'current_office_id' => $currentOfficeId,
            'processing_note' => 'Never serialize this note.',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function route(
        int $documentId,
        int $fromOfficeId,
        int $toOfficeId,
        string $forwardedAt = '2026-08-10 00:30:00',
        ?string $receivedAt = '2026-08-10 01:00:00',
        ?string $remarks = null
    ): int {
        return (int) DB::table('document_routes')->insertGetId([
            'document_id' => $documentId,
            'from_office_id' => $fromOfficeId,
            'to_office_id' => $toOfficeId,
            'forwarded_at' => $forwardedAt,
            'received_at' => $receivedAt,
            'remarks' => $remarks,
            'created_at' => $forwardedAt,
            'updated_at' => $forwardedAt,
        ]);
    }

    private function snapshot(): array
    {
        return [
            'documents' => DB::table('documents')
                ->orderBy('id')
                ->get([
                    'id',
                    'tracking_no',
                    'title',
                    'description',
                    'status',
                    'document_type_id',
                    'status_id',
                    'priority_id',
                    'confidentiality_level_id',
                    'origin_office_id',
                    'current_office_id',
                    'current_action_id',
                    'processing_note',
                    'current_action_updated_by',
                    'current_action_updated_at',
                    'created_by',
                    'document_date',
                    'due_date',
                    'created_at',
                    'updated_at',
                ])
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'tracking_no' => (string) $row->tracking_no,
                    'title' => (string) $row->title,
                    'description' => $this->nullableString($row->description),
                    'status' => (string) $row->status,
                    'document_type_id' => $this->nullableInt(
                        $row->document_type_id
                    ),
                    'status_id' => $row->status_id !== null
                        ? (int) $row->status_id
                        : null,
                    'priority_id' => $this->nullableInt($row->priority_id),
                    'confidentiality_level_id' => $this->nullableInt(
                        $row->confidentiality_level_id
                    ),
                    'origin_office_id' => $row->origin_office_id !== null
                        ? (int) $row->origin_office_id
                        : null,
                    'current_office_id' => $row->current_office_id !== null
                        ? (int) $row->current_office_id
                        : null,
                    'current_action_id' => $this->nullableInt(
                        $row->current_action_id
                    ),
                    'processing_note' => $this->nullableString(
                        $row->processing_note
                    ),
                    'current_action_updated_by' => $this->nullableInt(
                        $row->current_action_updated_by
                    ),
                    'current_action_updated_at' => $this->nullableString(
                        $row->current_action_updated_at
                    ),
                    'created_by' => $this->nullableInt($row->created_by),
                    'document_date' => $this->nullableString(
                        $row->document_date
                    ),
                    'due_date' => $this->nullableString($row->due_date),
                    'created_at' => $this->nullableString($row->created_at),
                    'updated_at' => $this->nullableString($row->updated_at),
                ])
                ->all(),
            'document_routes' => DB::table('document_routes')
                ->orderBy('id')
                ->get([
                    'id',
                    'document_id',
                    'from_office_id',
                    'to_office_id',
                    'forwarded_by',
                    'received_by',
                    'forwarded_at',
                    'received_at',
                    'status_id',
                    'action_id',
                    'remarks',
                    'created_at',
                    'updated_at',
                ])
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'document_id' => (int) $row->document_id,
                    'from_office_id' => (int) $row->from_office_id,
                    'to_office_id' => (int) $row->to_office_id,
                    'forwarded_by' => $this->nullableInt($row->forwarded_by),
                    'received_by' => $this->nullableInt($row->received_by),
                    'forwarded_at' => $this->nullableString($row->forwarded_at),
                    'received_at' => $this->nullableString($row->received_at),
                    'status_id' => $this->nullableInt($row->status_id),
                    'action_id' => $this->nullableInt($row->action_id),
                    'remarks' => $this->nullableString($row->remarks),
                    'created_at' => $this->nullableString($row->created_at),
                    'updated_at' => $this->nullableString($row->updated_at),
                ])
                ->all(),
            'processing_logs' => DB::table('document_processing_logs')
                ->orderBy('id')
                ->get([
                    'id',
                    'document_id',
                    'office_id',
                    'user_id',
                    'processing_action_id',
                    'document_route_id',
                    'event_type',
                    'processing_note',
                    'event_note',
                    'created_at',
                    'updated_at',
                ])
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'document_id' => (int) $row->document_id,
                    'office_id' => $this->nullableInt($row->office_id),
                    'user_id' => $this->nullableInt($row->user_id),
                    'processing_action_id' => $this->nullableInt(
                        $row->processing_action_id
                    ),
                    'document_route_id' => $this->nullableInt(
                        $row->document_route_id
                    ),
                    'event_type' => $this->nullableString($row->event_type),
                    'processing_note' => $this->nullableString(
                        $row->processing_note
                    ),
                    'event_note' => $this->nullableString($row->event_note),
                    'created_at' => $this->nullableString($row->created_at),
                    'updated_at' => $this->nullableString($row->updated_at),
                ])
                ->all(),
            'audit_count' => DB::table('audit_logs')->count(),
            'audit_rows' => DB::table('audit_logs')
                ->orderBy('id')
                ->get([
                    'id',
                    'user_id',
                    'module',
                    'action',
                    'record_id',
                    'description',
                    'ip_address',
                    'user_agent',
                    'created_at',
                    'updated_at',
                ])
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'user_id' => $this->nullableInt($row->user_id),
                    'module' => (string) $row->module,
                    'action' => (string) $row->action,
                    'record_id' => $this->nullableInt($row->record_id),
                    'description' => $this->nullableString(
                        $row->description
                    ),
                    'ip_address' => $this->nullableString($row->ip_address),
                    'user_agent' => $this->nullableString($row->user_agent),
                    'created_at' => $this->nullableString($row->created_at),
                    'updated_at' => $this->nullableString($row->updated_at),
                ])
                ->all(),
            'token_count' => DB::table('personal_access_tokens')->count(),
            'token_rows' => DB::table('personal_access_tokens')
                ->orderBy('id')
                ->get([
                    'id',
                    'tokenable_type',
                    'tokenable_id',
                    'name',
                    'abilities',
                    'last_used_at',
                    'expires_at',
                    'created_at',
                    'updated_at',
                ])
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'tokenable_type' => (string) $row->tokenable_type,
                    'tokenable_id' => (int) $row->tokenable_id,
                    'name' => (string) $row->name,
                    'abilities' => $this->normalizedAbilities(
                        $row->abilities
                    ),
                    'last_used_at' => $this->nullableString(
                        $row->last_used_at
                    ),
                    'expires_at' => $this->nullableString($row->expires_at),
                    'created_at' => $this->nullableString($row->created_at),
                    'updated_at' => $this->nullableString($row->updated_at),
                ])
                ->all(),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value !== null ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value !== null ? (string) $value : null;
    }

    private function normalizedAbilities(mixed $value): array|string|null
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        if (!is_array($decoded)) {
            return (string) $value;
        }

        $abilities = array_map(
            static fn (mixed $ability): string => (string) $ability,
            $decoded
        );
        sort($abilities, SORT_STRING);

        return $abilities;
    }

    private function readOnlyRequest(callable $request): TestResponse
    {
        $before = $this->snapshot();
        $response = $request();
        $after = $this->snapshot();

        $this->assertSame($before, $after);

        return $response;
    }
}
