<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentListApiContractTest extends TestCase
{
    private int $sequence = 0;

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
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('type_name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('document_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('status_name');
            $table->timestamps();
        });

        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('priority_name');
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

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module');
            $table->string('action');
            $table->timestamps();
        });

        Schema::create('document_processing_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('event_type')->nullable();
            $table->text('event_note')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'document_processing_logs',
            'audit_logs',
            'document_routes',
            'documents',
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

    public function test_unauthenticated_list_requests_are_rejected(): void
    {
        $this->createReadOnlyFixture();

        foreach ([
            '/api/documents',
            '/api/documents/incoming',
            '/api/documents/outgoing',
        ] as $endpoint) {
            $before = $this->readOnlyStateSnapshot();

            $this->getJson($endpoint)->assertUnauthorized();

            $this->assertReadOnlyStateUnchanged($before);
        }
    }

    public function test_role_without_document_view_is_forbidden(): void
    {
        $office = $this->createReadOnlyFixture();
        Sanctum::actingAs($this->createUser('Unknown Role', $office));

        foreach ([
            '/api/documents',
            '/api/documents/incoming',
            '/api/documents/outgoing',
        ] as $endpoint) {
            $before = $this->readOnlyStateSnapshot();

            $this->getJson($endpoint)->assertForbidden();

            $this->assertReadOnlyStateUnchanged($before);
        }
    }

    public function test_missing_office_is_forbidden_for_movement_lists(): void
    {
        $this->createReadOnlyFixture();
        Sanctum::actingAs($this->createUser('Administrator', null));

        $beforeIncoming = $this->readOnlyStateSnapshot();
        $this->getJson('/api/documents/incoming')->assertForbidden();
        $this->assertReadOnlyStateUnchanged($beforeIncoming);

        $beforeOutgoing = $this->readOnlyStateSnapshot();
        $this->getJson('/api/documents/outgoing')->assertForbidden();
        $this->assertReadOnlyStateUnchanged($beforeOutgoing);
    }

    public function test_all_documents_remains_system_wide_newest_first_with_exact_safe_shape(): void
    {
        $origin = $this->createOffice('ORIGIN');
        $current = $this->createOffice('CURRENT');
        $type = $this->createLookup('document_types', 'type_name', 'Memo');
        $status = $this->createLookup('document_statuses', 'status_name', 'Received');
        $priority = $this->createLookup('priorities', 'priority_name', 'Normal');
        $older = $this->createDocument($origin, $origin, now()->subDay(), 'Older');
        $newer = $this->createDocument(
            $origin,
            $current,
            now(),
            'Newer',
            $type,
            $status,
            $priority
        );
        Sanctum::actingAs($this->createUser('Viewer', $origin));

        $response = $this->getJson('/api/documents')->assertOk();
        $data = $response->json();

        $this->assertSame([$newer->id, $older->id], array_column($data, 'id'));
        $this->assertSame([
            'id',
            'tracking_no',
            'title',
            'type',
            'status',
            'priority',
            'current_office',
            'created_at',
        ], array_keys($data[0]));
        $this->assertSame(['id', 'type_name'], array_keys($data[0]['type']));
        $this->assertSame(['id', 'status_name'], array_keys($data[0]['status']));
        $this->assertSame(['id', 'priority_name'], array_keys($data[0]['priority']));
        $this->assertSame(['id', 'office_name'], array_keys($data[0]['current_office']));
        $response
            ->assertJsonMissingPath('0.description')
            ->assertJsonMissingPath('0.processing_note')
            ->assertJsonMissingPath('0.creator');
    }

    public function test_incoming_uses_historical_destinations_includes_pending_and_received_and_selects_newest_route(): void
    {
        $office = $this->createOffice('USER');
        $oldSender = $this->createOffice('OLD');
        $newSender = $this->createOffice('NEW');
        $other = $this->createOffice('OTHER');
        $received = $this->createDocument($other, $other, now()->subDay(), 'Received');
        $pending = $this->createDocument($other, $office, now(), 'Pending');
        $originOnly = $this->createDocument($office, $other, now()->addMinute(), 'Origin only');
        $custodyOnly = $this->createDocument($other, $office, now()->addMinutes(2), 'Custody only');

        $this->createRoute($received, $oldSender, $office, now()->subDays(2), now()->subDay());
        $this->createRoute($received, $newSender, $office, now()->subHours(2), now()->subHour());
        $this->createRoute($pending, $other, $office, now()->subMinute(), null);
        Sanctum::actingAs($this->createUser('Records Officer', $office));

        $response = $this->getJson('/api/documents/incoming')->assertOk();
        $data = $response->json();

        $this->assertSame([$pending->id, $received->id], array_column($data, 'id'));
        $this->assertNotContains($originOnly->id, array_column($data, 'id'));
        $this->assertNotContains($custodyOnly->id, array_column($data, 'id'));
        $this->assertNull($data[0]['routes'][0]['received_at']);
        $this->assertSame('NEW Office', $data[1]['routes'][0]['from_office']['office_name']);
        $this->assertSame([
            'id', 'tracking_no', 'title', 'type', 'routes',
        ], array_keys($data[0]));
        $this->assertSame([
            'from_office', 'received_at',
        ], array_keys($data[0]['routes'][0]));
        $response
            ->assertJsonMissingPath('0.routes.0.remarks')
            ->assertJsonMissingPath('0.routes.0.forwarded_by')
            ->assertJsonMissingPath('0.description');
    }

    public function test_outgoing_uses_historical_senders_includes_pending_and_received_and_selects_newest_route(): void
    {
        $office = $this->createOffice('USER');
        $oldDestination = $this->createOffice('OLD');
        $newDestination = $this->createOffice('NEW');
        $other = $this->createOffice('OTHER');
        $received = $this->createDocument($office, $newDestination, now()->subDay(), 'Received');
        $pending = $this->createDocument($office, $oldDestination, now(), 'Pending');
        $originOnly = $this->createDocument($office, $other, now()->addMinute(), 'Origin only');

        $this->createRoute($received, $office, $oldDestination, now()->subDays(2), now()->subDay());
        $this->createRoute($received, $office, $newDestination, now()->subHours(2), now()->subHour());
        $this->createRoute($pending, $office, $oldDestination, now()->subMinute(), null);
        Sanctum::actingAs($this->createUser('Office User', $office));

        $response = $this->getJson('/api/documents/outgoing')->assertOk();
        $data = $response->json();

        $this->assertSame([$pending->id, $received->id], array_column($data, 'id'));
        $this->assertNotContains($originOnly->id, array_column($data, 'id'));
        $this->assertSame('NEW Office', $data[1]['routes'][0]['to_office']['office_name']);
        $this->assertSame([
            'to_office', 'forwarded_at',
        ], array_keys($data[0]['routes'][0]));
        $response
            ->assertJsonMissingPath('0.routes.0.remarks')
            ->assertJsonMissingPath('0.routes.0.received_by')
            ->assertJsonMissingPath('0.description');
    }

    public function test_document_can_appear_in_both_lists_and_empty_office_gets_empty_arrays(): void
    {
        $office = $this->createOffice('USER');
        $other = $this->createOffice('OTHER');
        $empty = $this->createOffice('EMPTY');
        $document = $this->createDocument($office, $office, now(), 'Round trip');
        $this->createRoute($document, $office, $other, now()->subHour(), now()->subMinutes(30));
        $this->createRoute($document, $other, $office, now()->subMinute(), null);

        Sanctum::actingAs($this->createUser('Administrator', $office));
        $this->getJson('/api/documents/incoming')
            ->assertOk()->assertJsonPath('0.id', $document->id);
        $this->getJson('/api/documents/outgoing')
            ->assertOk()->assertJsonPath('0.id', $document->id);

        Sanctum::actingAs($this->createUser('Viewer', $empty));
        $this->getJson('/api/documents/incoming')->assertOk()->assertExactJson([]);
        $this->getJson('/api/documents/outgoing')->assertOk()->assertExactJson([]);
    }

    public function test_list_requests_create_no_audits_or_business_mutations(): void
    {
        $office = $this->createOffice('USER');
        $other = $this->createOffice('OTHER');
        $document = $this->createDocument($other, $office, now(), 'Read only');
        $this->createRoute($document, $other, $office, now(), null);
        Sanctum::actingAs($this->createUser('Viewer', $office));
        $before = [Document::count(), DB::table('document_routes')->count()];

        $this->getJson('/api/documents')->assertOk();
        $this->getJson('/api/documents/incoming')->assertOk();
        $this->getJson('/api/documents/outgoing')->assertOk();

        $this->assertSame($before, [Document::count(), DB::table('document_routes')->count()]);
        $this->assertSame(0, DB::table('audit_logs')->count());
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

    private function createReadOnlyFixture(): int
    {
        $office = $this->createOffice('FIXTURE-'.$this->sequence);
        $otherOffice = $this->createOffice('OTHER-'.$this->sequence);
        $document = $this->createDocument(
            $otherOffice,
            $office,
            now(),
            'Rejected request fixture'
        );
        $this->createRoute(
            $document,
            $otherOffice,
            $office,
            now(),
            null
        );

        DB::table('document_processing_logs')->insert([
            'document_id' => $document->id,
            'event_type' => 'fixture',
            'event_note' => 'Existing business history',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $office;
    }

    private function readOnlyStateSnapshot(): array
    {
        return [
            'document_count' => DB::table('documents')->count(),
            'documents' => DB::table('documents')
                ->orderBy('id')
                ->get([
                    'id',
                    'tracking_no',
                    'title',
                    'current_office_id',
                    'status_id',
                    'updated_at',
                ])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'route_count' => DB::table('document_routes')->count(),
            'routes' => DB::table('document_routes')
                ->orderBy('id')
                ->get([
                    'id',
                    'document_id',
                    'from_office_id',
                    'to_office_id',
                    'received_at',
                    'remarks',
                    'updated_at',
                ])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'audit_count' => DB::table('audit_logs')->count(),
            'audits' => DB::table('audit_logs')
                ->orderBy('id')
                ->get(['id', 'user_id', 'module', 'action', 'updated_at'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'processing_history_count' =>
                DB::table('document_processing_logs')->count(),
            'processing_history' => DB::table('document_processing_logs')
                ->orderBy('id')
                ->get([
                    'id',
                    'document_id',
                    'event_type',
                    'event_note',
                    'updated_at',
                ])
                ->map(fn ($row): array => (array) $row)
                ->all(),
        ];
    }

    private function assertReadOnlyStateUnchanged(array $before): void
    {
        $after = $this->readOnlyStateSnapshot();

        foreach ([
            'document_count',
            'documents',
            'route_count',
            'routes',
            'audit_count',
            'audits',
            'processing_history_count',
            'processing_history',
        ] as $stateKey) {
            $this->assertSame(
                $before[$stateKey],
                $after[$stateKey],
                "Rejected list request changed {$stateKey}."
            );
        }
    }

    private function createLookup(string $table, string $column, string $value): int
    {
        return DB::table($table)->insertGetId([
            $column => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(string $roleName, ?int $officeId): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $this->sequence++;

        return User::create([
            'name' => $roleName.' User',
            'email' => "user{$this->sequence}@example.test",
            'password' => 'test-password',
            'role_id' => $role->id,
            'office_id' => $officeId,
        ]);
    }

    private function createDocument(
        int $originOfficeId,
        int $currentOfficeId,
        $createdAt,
        string $title,
        ?int $typeId = null,
        ?int $statusId = null,
        ?int $priorityId = null
    ): Document {
        $this->sequence++;

        $id = DB::table('documents')->insertGetId([
            'tracking_no' => 'DOC-'.$this->sequence,
            'title' => $title,
            'description' => 'Sensitive description',
            'processing_note' => 'Sensitive processing note',
            'document_type_id' => $typeId,
            'status_id' => $statusId,
            'priority_id' => $priorityId,
            'origin_office_id' => $originOfficeId,
            'current_office_id' => $currentOfficeId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return Document::findOrFail($id);
    }

    private function createRoute(
        Document $document,
        int $fromOfficeId,
        int $toOfficeId,
        $forwardedAt,
        $receivedAt
    ): void {
        DB::table('document_routes')->insert([
            'document_id' => $document->id,
            'from_office_id' => $fromOfficeId,
            'to_office_id' => $toOfficeId,
            'forwarded_at' => $forwardedAt,
            'received_at' => $receivedAt,
            'remarks' => 'Sensitive route remarks',
            'created_at' => $forwardedAt,
            'updated_at' => $forwardedAt,
        ]);
    }
}
