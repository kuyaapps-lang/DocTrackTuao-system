<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Process9C1DocumentReadSecurityTest extends TestCase
{
    private int $sequence = 0;
    private int $documentId;
    private array $offices;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
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
        foreach ([
            'document_types' => 'type_name',
            'document_statuses' => 'status_name',
            'priorities' => 'priority_name',
            'confidentiality_levels' => 'level_name',
        ] as $table => $column) {
            Schema::create($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->id();
                $blueprint->string($column);
                $blueprint->timestamps();
            });
        }
        Schema::create('processing_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('action_code');
            $table->string('action_name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('route_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('action_name');
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table): void {
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
            $table->string('event_type');
            $table->text('processing_note')->nullable();
            $table->text('event_note')->nullable();
            $table->timestamps();
        });
        Schema::create('document_qr_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('qr_token');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('status');
            $table->timestamps();
        });

        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        foreach ([
            'document_qr_codes', 'document_processing_logs', 'document_routes',
            'documents', 'route_actions', 'processing_actions',
            'confidentiality_levels', 'priorities', 'document_statuses',
            'document_types', 'users', 'offices', 'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_system_roles_can_read_every_protected_endpoint(): void
    {
        foreach (['Administrator', 'Records Officer'] as $role) {
            $this->assertAllEndpointsAllowed($this->user($role, null));
        }
    }

    public function test_office_roles_can_read_through_each_office_universe_path(): void
    {
        foreach (['Office User', 'Viewer'] as $role) {
            foreach (['origin', 'current', 'sender', 'destination'] as $scope) {
                $this->assertAllEndpointsAllowed(
                    $this->user($role, $this->offices[$scope])
                );
            }
        }
    }

    public function test_unrelated_missing_and_invalid_offices_are_forbidden(): void
    {
        $deletedOfficeId = DB::table('offices')->insertGetId([
            'office_name' => 'Deleted Office',
            'office_code' => 'DELETED-'.$this->sequence++,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('offices')->where('id', $deletedOfficeId)->delete();

        foreach (['Office User', 'Viewer'] as $role) {
            foreach ([
                $this->offices['unrelated'],
                null,
                $deletedOfficeId,
            ] as $officeId) {
                $this->assertAllEndpointsHaveStatus(
                    $this->user($role, $officeId),
                    403
                );
            }
        }
    }

    public function test_unexpected_role_never_gains_system_wide_direct_access(): void
    {
        Gate::define('documents.view', fn (User $user): bool => true);

        $this->assertAllEndpointsHaveStatus(
            $this->user('Unexpected Role', $this->offices['unrelated']),
            403
        );
    }

    public function test_role_without_view_permission_is_forbidden(): void
    {
        $this->assertAllEndpointsHaveStatus(
            $this->user('Unexpected Role', $this->offices['origin']),
            403
        );
    }

    public function test_unauthenticated_requests_remain_unauthorized(): void
    {
        foreach ($this->endpoints() as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }
    }

    private function assertAllEndpointsAllowed(User $user): void
    {
        Sanctum::actingAs($user);

        foreach ($this->endpoints() as $endpoint) {
            $response = $this->getJson($endpoint)->assertOk();
            $content = strtolower($response->getContent());

            foreach ([
                '@example.test', 'password', 'remember_token', 'abilities',
                'stored_filename', 'file_path', 'private-storage-marker',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $content);
            }
        }
    }

    private function assertAllEndpointsHaveStatus(User $user, int $status): void
    {
        Sanctum::actingAs($user);

        foreach ($this->endpoints() as $endpoint) {
            $this->getJson($endpoint)->assertStatus($status);
        }
    }

    private function endpoints(): array
    {
        $base = "/api/documents/{$this->documentId}";

        return [$base, "$base/routing-options", "$base/history", "$base/processing"];
    }

    private function seedFixture(): void
    {
        foreach (['origin', 'current', 'sender', 'destination', 'unrelated'] as $name) {
            $this->offices[$name] = DB::table('offices')->insertGetId([
                'office_name' => ucfirst($name).' Office',
                'office_code' => strtoupper(substr($name, 0, 6)).$this->sequence++,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $type = DB::table('document_types')->insertGetId(['type_name' => 'Memo']);
        $status = DB::table('document_statuses')->insertGetId(['status_name' => 'Received']);
        $priority = DB::table('priorities')->insertGetId(['priority_name' => 'Normal']);
        $confidentiality = DB::table('confidentiality_levels')->insertGetId(['level_name' => 'Public']);
        $action = DB::table('processing_actions')->insertGetId([
            'action_code' => 'REVIEW', 'action_name' => 'Review',
            'is_active' => true, 'sort_order' => 1,
        ]);
        $routeAction = DB::table('route_actions')->insertGetId(['action_name' => 'Forward']);
        $actor = $this->user('Office User', $this->offices['current']);

        $this->documentId = DB::table('documents')->insertGetId([
            'tracking_no' => 'P9C1-'.uniqid(), 'title' => 'Scoped document',
            'description' => 'Supported detail', 'document_type_id' => $type,
            'status_id' => $status, 'priority_id' => $priority,
            'confidentiality_level_id' => $confidentiality,
            'origin_office_id' => $this->offices['origin'],
            'current_office_id' => $this->offices['current'],
            'current_action_id' => $action, 'processing_note' => 'Required note',
            'current_action_updated_by' => $actor->id,
            'current_action_updated_at' => now(), 'created_by' => $actor->id,
            'document_date' => '2026-09-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $routeId = DB::table('document_routes')->insertGetId([
            'document_id' => $this->documentId,
            'from_office_id' => $this->offices['sender'],
            'to_office_id' => $this->offices['destination'],
            'forwarded_by' => $actor->id, 'received_by' => $actor->id,
            'forwarded_at' => now(), 'received_at' => now(),
            'status_id' => $status, 'action_id' => $routeAction,
            'remarks' => 'Required routing remark',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('document_processing_logs')->insert([
            'document_id' => $this->documentId, 'office_id' => $this->offices['current'],
            'user_id' => $actor->id, 'processing_action_id' => $action,
            'document_route_id' => $routeId, 'event_type' => 'action_updated',
            'processing_note' => 'Required note', 'event_note' => 'Required event',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('document_qr_codes')->insert([
            'qr_token' => 'SAFE-QR-TOKEN', 'document_id' => $this->documentId,
            'status' => 'registered', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(string $roleName, ?int $officeId): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $this->sequence++;

        return User::create([
            'name' => $roleName.' '.$this->sequence,
            'email' => 'p9c1-'.$this->sequence.'@example.test',
            'password' => 'fixture-only-password',
            'role_id' => $role->id,
            'office_id' => $officeId,
        ]);
    }
}
