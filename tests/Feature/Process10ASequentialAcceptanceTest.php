<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class Process10ASequentialAcceptanceTest extends TestCase
{
    private array $offices = [];
    private array $users = [];
    private array $tokens = [];
    private mixed $previousClock = null;
    private string $defaultGuard;
    private const TABLES = ['roles', 'departments', 'offices', 'users', 'personal_access_tokens',
        'document_types', 'document_statuses', 'priorities', 'confidentiality_levels',
        'processing_actions', 'route_actions', 'documents', 'document_qr_codes',
        'document_routes', 'document_processing_logs', 'audit_logs', 'document_attachments'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultGuard = config('auth.defaults.guard');
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->previousClock = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse('2026-09-09 02:00:00', 'UTC'));
        $this->createSchema();
        Schema::create('departments', function (Blueprint $table) {
            $table->id(); $table->string('department_name');
        });
        Schema::create('document_attachments', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('document_id');
            $table->string('original_name'); $table->string('file_path');
            $table->timestamps();
        });
        $this->seedLookups();
        DB::table('processing_actions')->insert([
            'action_code' => 'INACTIVE', 'action_name' => 'Inactive test action', 'is_active' => false,
        ]);
        foreach (['A', 'B', 'C', 'D'] as $code) {
            $this->offices[$code] = DB::table('offices')->insertGetId([
                'office_name' => 'Synthetic Office '.$code, 'office_code' => 'TEST-'.$code,
            ]);
        }
        foreach (['Administrator', 'Records Officer', 'Office User', 'Viewer'] as $role) {
            Role::create(['name' => $role]);
        }
        $password = bin2hex(random_bytes(24));
        foreach (['admin' => ['Administrator', 'A'], 'records' => ['Records Officer', 'A'],
            'b' => ['Office User', 'B'], 'c' => ['Office User', 'C'], 'd' => ['Office User', 'D'],
            'viewer' => ['Viewer', 'B'], 'missing' => ['Office User', null]] as $key => [$role, $office]) {
            $this->users[$key] = User::create([
                'name' => 'Synthetic '.$key, 'email' => $key.'@example.test', 'password' => $password,
                'role_id' => Role::where('name', $role)->value('id'),
                'office_id' => $office === null ? null : $this->offices[$office],
            ]);
        }
        $fixtures = $this->snapshot();
        foreach ($this->users as $key => $user) {
            $login = $this->requestAs(null, 'POST', '/api/login', [
                'email' => $user->email, 'password' => $password,
            ], 200);
            $this->assertTrue(is_string($login->json('token')) && $login->json('token') !== '');
            $this->tokens[$key] = $login->json('token');
            // Prime last_used_at before baseline. A frozen clock makes later auth reads stable.
            $this->requestAs($key, 'GET', '/api/offices', [], 200);
        }
        $authenticated = $this->snapshot();
        foreach (self::TABLES as $table) {
            if (!in_array($table, ['personal_access_tokens', 'audit_logs'], true)) {
                $this->assertSame($fixtures[$table], $authenticated[$table], $table.' fixture preservation during authentication');
            }
        }
    }

    protected function tearDown(): void
    {
        $this->tokens = [];
        $this->users = [];
        try {
            // Laravel discards the in-memory connection, guards, configuration,
            // session, array cache and application listeners with this instance.
            parent::tearDown();
        } finally {
            Carbon::setTestNow($this->previousClock);
        }
    }

    public function test_complete_sequential_hard_copy_workflow_and_rejections(): void
    {
        $this->assertSame(4, DB::table('offices')->count());
        $this->assertSame(7, DB::table('users')->count());
        $this->assertSame(7, DB::table('personal_access_tokens')->count());
        $this->assertSame(array_fill(0, 7, 'login'), DB::table('audit_logs')->orderBy('id')->pluck('action')->all());
        foreach (['documents', 'document_qr_codes', 'document_routes', 'document_processing_logs', 'document_attachments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' authentication baseline');
        }
        $this->assertSame(array_map(fn ($user) => $user->id, array_values($this->users)),
            DB::table('audit_logs')->orderBy('id')->pluck('user_id')->all());
        $baseline = $this->snapshot();
        $baselineAuditId = (int) DB::table('audit_logs')->max('id');
        $payload = ['title' => 'Synthetic hard-copy acceptance document',
            'document_type_id' => $this->lookup('document_types'), 'priority_id' => $this->lookup('priorities'),
            'confidentiality_level_id' => $this->lookup('confidentiality_levels'),
            'origin_office_id' => $this->offices['A'], 'document_date' => '2026-09-09'];

        $this->reject(null, 'POST', '/api/qr-codes', ['quantity' => 1], 401);
        $this->reject('viewer', 'POST', '/api/qr-codes', ['quantity' => 1], 403);
        $this->reject('b', 'POST', '/api/documents', $payload, 403);
        $this->reject('records', 'POST', '/api/documents', $payload + ['qr_token' => 'invalid-synthetic-qr'], 422);
        $issued = $this->requestAs('records', 'POST', '/api/qr-codes', ['quantity' => 1], 201);
        $qrId = $issued->json('qr_codes.0.id');
        $qrToken = $issued->json('qr_codes.0.qr_token');
        $this->assertTrue(is_int($qrId) && is_string($qrToken) && $qrToken !== '');
        $resolved = $this->readUnchanged(null, '/api/q/'.$qrToken, 200);
        $this->assertSame('unused', $resolved->json('state'));
        $this->readUnchanged(null, '/api/q/invalid-synthetic-qr', 404);
        $payload['qr_token'] = $qrToken;
        $this->reject(null, 'POST', '/api/documents', $payload, 401);
        $registered = $this->requestAs('records', 'POST', '/api/documents', $payload, 201);
        $id = $registered->json('document.id');
        $this->assertTrue(is_int($id));
        $this->assertTrue($registered->json('qr_linked'));
        $base = '/api/documents/'.$id;
        $this->assertSame($id, DB::table('document_qr_codes')->where('id', $qrId)->value('document_id'));
        $this->assertSame('registered', DB::table('document_qr_codes')->where('id', $qrId)->value('status'));
        $this->assertWorkflowState($id, 'A', 'REGISTERED', 0);
        $this->reject('records', 'POST', '/api/documents', $payload, 422);
        $this->reject('viewer', 'POST', '/api/documents', $payload, 403);

        foreach (['admin', 'records'] as $actor) {
            $this->readUnchanged($actor, $base, 200);
        }
        foreach (['b', 'c', 'd', 'viewer', 'missing'] as $actor) {
            $this->readUnchanged($actor, $base, 403);
        }
        $this->readUnchanged(null, $base, 401);
        $forward = ['to_office_id' => $this->offices['B']];
        $process = ['current_action_id' => $this->action('UNDER_REVIEW'), 'processing_note' => 'Synthetic processing note.'];
        foreach (['d', 'missing', 'viewer'] as $actor) {
            $this->reject($actor, 'POST', $base.'/forward', $forward, 403);
            $this->reject($actor, 'PUT', $base.'/processing', $process, 403);
        }
        $this->reject(null, 'POST', $base.'/forward', $forward, 401);
        $this->reject('records', 'POST', $base.'/forward', ['to_office_id' => 999999], 422);
        $this->reject('records', 'POST', $base.'/forward', ['to_office_id' => $this->offices['A']], 422);
        $first = $this->requestAs('records', 'POST', $base.'/forward', $forward, 201)->json('route.id');
        $this->assertWorkflowState($id, 'B', 'AWAITING_RECEIPT', 1);
        $this->reject('records', 'POST', $base.'/forward', $forward, 403);
        $this->reject('b', 'POST', $base.'/forward', ['to_office_id' => $this->offices['C']], 409);
        $this->reject('b', 'PUT', $base.'/processing', $process, 409);
        foreach (['d', 'missing', 'viewer'] as $actor) {
            $this->reject($actor, 'POST', $base.'/receive', [], 403);
        }
        $this->reject(null, 'POST', $base.'/receive', [], 401);
        $this->requestAs('b', 'POST', $base.'/receive', [], 200);
        $this->assertWorkflowState($id, 'B', 'FOR_ACTION', 0);
        $this->reject('b', 'POST', $base.'/receive', [], 409);
        $this->reject('b', 'PUT', $base.'/processing', ['current_action_id' => 999999], 422);
        $this->reject('b', 'PUT', $base.'/processing', ['current_action_id' => $this->action('INACTIVE')], 422);
        $this->reject('b', 'PUT', $base.'/processing', ['current_action_id' => []], 422);
        $this->reject('b', 'PUT', $base.'/processing', ['current_action_id' => $this->action('REGISTERED')], 422);
        $this->reject(null, 'PUT', $base.'/processing', $process, 401);
        // Viewer is in the holding office here: role denial cannot be hidden by office denial.
        $this->reject('viewer', 'PUT', $base.'/processing', $process, 403);
        $this->reject('viewer', 'POST', $base.'/forward', ['to_office_id' => $this->offices['C']], 403);
        $this->reject('viewer', 'PUT', $base, ['title' => 'Rejected synthetic edit'], 403);
        $this->reject('viewer', 'DELETE', $base, [], 403);
        $this->reject('viewer', 'POST', $base.'/attachments', [], 403);
        $this->reject('viewer', 'POST', '/api/qr-codes/'.$qrId.'/void', [], 403);
        $this->requestAs('b', 'PUT', $base.'/processing', $process, 200);
        $this->assertWorkflowState($id, 'B', 'UNDER_REVIEW', 0);
        $this->assertSame('Synthetic processing note.', DB::table('documents')->where('id', $id)->value('processing_note'));
        $second = $this->requestAs('b', 'POST', $base.'/forward', ['to_office_id' => $this->offices['C']], 201)->json('route.id');
        $this->assertWorkflowState($id, 'C', 'AWAITING_RECEIPT', 1);
        $this->reject('c', 'PUT', $base.'/processing', $process, 409);
        $this->requestAs('c', 'POST', $base.'/receive', [], 200);
        $this->assertWorkflowState($id, 'C', 'FOR_ACTION', 0);
        $this->reject('c', 'POST', $base.'/receive', [], 409);
        $this->reject('records', 'POST', '/api/documents', $payload, 422);
        foreach (['admin', 'records', 'b'] as $actor) {
            $this->reject($actor, 'PUT', $base.'/processing', $process, 403);
            $this->reject($actor, 'POST', $base.'/forward', ['to_office_id' => $this->offices['D']], 403);
        }

        $document = DB::table('documents')->where('id', $id)->first();
        $this->assertSame($this->offices['A'], $document->origin_office_id);
        $this->assertSame($this->offices['C'], $document->current_office_id);
        $this->assertSame($this->users['records']->id, $document->created_by);
        $this->assertSame($this->users['c']->id, $document->current_action_updated_by);
        $this->assertSame($this->action('FOR_ACTION'), $document->current_action_id);
        $this->assertNull($document->processing_note);
        $resolved = $this->readUnchanged(null, '/api/q/'.$qrToken, 200);
        $this->assertSame('registered', $resolved->json('state'));
        $this->assertTrue($resolved->json('qr_token') === $qrToken, 'QR association must survive routing');
        $this->assertSame($document->tracking_no, $resolved->json('tracking_no'));
        $qr = DB::table('document_qr_codes')->where('id', $qrId)->first();
        $this->assertSame($id, $qr->document_id);
        $this->assertSame($this->users['records']->id, $qr->generated_by);
        $this->assertNotNull($qr->generated_at);
        $this->assertNotNull($qr->registered_at);
        $routeRows = DB::table('document_routes')->orderBy('id')->get();
        $this->assertCount(2, $routeRows);
        foreach ([[$first, 'A', 'B', 'records', 'b'], [$second, 'B', 'C', 'b', 'c']] as $index => [$routeId, $from, $to, $sender, $receiver]) {
            $row = $routeRows[$index];
            $this->assertSame([$routeId, $id, $this->offices[$from], $this->offices[$to],
                $this->users[$sender]->id, $this->users[$receiver]->id],
                [$row->id, $row->document_id, $row->from_office_id, $row->to_office_id, $row->forwarded_by, $row->received_by]);
            $this->assertNotNull($row->received_at);
            $this->assertNotNull($row->forwarded_at);
            $this->assertSame('Received', DB::table('document_statuses')->where('id', $row->status_id)->value('status_name'));
        }
        $logs = DB::table('document_processing_logs')->orderBy('id')->get();
        $this->assertSame(['registered', 'forwarded', 'received', 'action_updated', 'forwarded', 'received'], $logs->pluck('event_type')->all());
        $this->assertSame(array_map(fn ($actor) => $this->users[$actor]->id,
            ['records', 'records', 'b', 'b', 'b', 'c']), $logs->pluck('user_id')->all());
        $this->assertSame([null, $first, $first, null, $second, $second], $logs->pluck('document_route_id')->all());
        $this->assertSame(array_fill(0, 6, $id), $logs->pluck('document_id')->all());
        // Awaiting-receipt history belongs to the destination, with the sender as actor.
        $this->assertSame(array_map(fn ($office) => $this->offices[$office], ['A', 'B', 'B', 'B', 'C', 'C']),
            $logs->pluck('office_id')->all());
        $this->assertSame(array_map(fn ($code) => $this->action($code),
            ['REGISTERED', 'AWAITING_RECEIPT', 'FOR_ACTION', 'UNDER_REVIEW', 'AWAITING_RECEIPT', 'FOR_ACTION']),
            $logs->pluck('processing_action_id')->all());
        $this->assertSame('Synthetic processing note.', $logs[3]->processing_note);
        $audits = DB::table('audit_logs')->where('id', '>', $baselineAuditId)->orderBy('id')->get();
        $this->assertSame(['generated', 'registered', 'created', 'forwarded', 'received', 'processing_updated', 'forwarded', 'received'], $audits->pluck('action')->all());
        $this->assertSame(['qr_codes', 'qr_codes', 'documents', 'document_routing', 'document_routing',
            'document_processing', 'document_routing', 'document_routing'], $audits->pluck('module')->all());
        $this->assertSame([$qrId, $qrId, $id, $id, $id, $id, $id, $id], $audits->pluck('record_id')->all());
        $this->assertSame(array_map(fn ($actor) => $this->users[$actor]->id,
            ['records', 'records', 'records', 'records', 'b', 'b', 'b', 'c']), $audits->pluck('user_id')->all());
        $this->assertSame(array_fill(0, 8, '192.0.2.10'), $audits->pluck('ip_address')->all());
        $this->assertSame(array_fill(0, 8, 'Process10A-Synthetic/1.0'), $audits->pluck('user_agent')->all());
        foreach ($audits as $audit) {
            $this->assertNotNull($audit->created_at);
            foreach (array_merge([$qrToken, $process['processing_note']], array_values($this->tokens)) as $sensitive) {
                $this->assertFalse(str_contains($audit->description ?? '', $sensitive), 'Audit description must omit secrets and processing notes');
            }
        }

        foreach (['admin', 'records', 'b', 'c', 'viewer'] as $actor) {
            $this->readUnchanged($actor, $base, 200);
            $history = $this->readUnchanged($actor, $base.'/history', 200);
            $this->assertSame([$first, $second], array_column($history->json(), 'id'));
            $processing = $this->readUnchanged($actor, $base.'/processing', 200);
            $this->assertSame(6, count($processing->json('history')));
            $this->assertSame(array_reverse($logs->pluck('id')->all()), array_column($processing->json('history'), 'id'));
            $this->assertSame($this->offices['C'], $processing->json('current_office.id'));
        }
        foreach (['d', 'missing'] as $actor) {
            foreach ([$base, $base.'/history', $base.'/processing'] as $url) {
                $this->readUnchanged($actor, $url, 403);
            }
        }
        foreach (['admin', 'records'] as $actor) {
            $auditResponse = $this->readUnchanged($actor, '/api/audit-logs?per_page=25', 200);
            $this->assertSame(15, $auditResponse->json('total'));
            $this->assertSame(array_reverse($audits->pluck('id')->all()),
                array_slice(array_column($auditResponse->json('data'), 'id'), 0, 8));
            $summary = $this->readUnchanged($actor, '/api/dashboard/summary', 200);
            $this->assertSame(['total_documents' => 1, 'incoming_movements' => 2,
                'outgoing_movements' => 2, 'in_transit_documents' => 0, 'received_documents' => 1], $summary->json('summary'));
        }
        foreach (['b' => [1, 1, 1], 'c' => [1, 1, 0], 'viewer' => [1, 1, 1], 'd' => [0, 0, 0]] as $actor => [$total, $incoming, $outgoing]) {
            $summary = $this->readUnchanged($actor, '/api/dashboard/summary', 200);
            $this->assertSame(['total_documents' => $total, 'incoming_movements' => $incoming,
                'outgoing_movements' => $outgoing, 'in_transit_documents' => 0, 'received_documents' => $total], $summary->json('summary'));
            $this->readUnchanged($actor, '/api/audit-logs', 403);
        }
        $this->readUnchanged('missing', '/api/dashboard/summary', 403);
        foreach (['admin', 'records', 'b', 'c', 'viewer', 'd'] as $actor) {
            $list = $this->readUnchanged($actor, '/api/documents', 200);
            // All Documents is the established global metadata list; detail is scoped separately.
            $this->assertSame([$id], array_column($list->json('data'), 'id'));
            foreach (['incoming', 'outgoing'] as $direction) {
                $movement = $this->readUnchanged($actor, '/api/documents/'.$direction, 200);
                $visible = $direction === 'incoming'
                    ? in_array($actor, ['b', 'c', 'viewer'], true)
                    : in_array($actor, ['admin', 'records', 'b', 'viewer'], true);
                $this->assertSame($visible ? [$id] : [], array_column($movement->json('data'), 'id'));
            }
        }
        foreach (['incoming', 'outgoing'] as $direction) {
            $this->readUnchanged('missing', '/api/documents/'.$direction, 403);
        }
        $after = $this->snapshot();
        $effects = ['documents' => 1, 'document_qr_codes' => 1, 'document_routes' => 2,
            'document_processing_logs' => 6, 'audit_logs' => 8, 'document_attachments' => 0];
        foreach (self::TABLES as $table) {
            $this->assertSame($effects[$table] ?? 0, count($after[$table]) - count($baseline[$table]), $table.' row delta');
            $this->assertSame($baseline[$table], array_slice($after[$table], 0, count($baseline[$table])), $table.' baseline preservation');
        }
    }

    private function assertWorkflowState(int $id, string $office, string $action, int $pending): void
    {
        $document = DB::table('documents')->where('id', $id)->first();
        $this->assertSame($this->offices[$office], $document->current_office_id);
        $this->assertSame($this->action($action), $document->current_action_id);
        $this->assertSame($pending, DB::table('document_routes')->where('document_id', $id)->whereNull('received_at')->count());
    }

    private function requestAs(?string $actor, string $method, string $url, array $payload, int $status): TestResponse
    {
        Auth::forgetGuards();
        // Protected requests select Sanctum as the default guard in this shared
        // test application; a fresh HTTP request starts with the web login guard.
        Auth::shouldUse($this->defaultGuard);
        $this->app['session']->flush();
        $this->flushHeaders();
        $headers = $actor === null ? [] : ['Authorization' => 'Bearer '.$this->tokens[$actor]];
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_USER_AGENT' => 'Process10A-Synthetic/1.0']);
        $response = $this->json($method, $url, $payload, $headers);
        // Do not let TestResponse's failure formatter print secret-bearing response bodies.
        $this->assertSame($status, $response->getStatusCode(), $method.' workflow request as '.($actor ?? 'anonymous'));
        return $response;
    }

    private function reject(?string $actor, string $method, string $url, array $payload, int $status): void
    {
        $before = $this->snapshot();
        $this->requestAs($actor, $method, $url, $payload, $status);
        $this->assertSame($before, $this->snapshot(), 'Rejected request must preserve all normalized rows');
    }

    private function readUnchanged(?string $actor, string $url, int $status): TestResponse
    {
        $before = $this->snapshot();
        $response = $this->requestAs($actor, 'GET', $url, [], $status);
        $this->assertSame($before, $this->snapshot(), 'Reads must preserve baseline state');
        return $response;
    }

    private function snapshot(): array
    {
        $state = [];
        foreach (self::TABLES as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(function ($row) {
                $fields = (array) $row;
                foreach (['password', 'remember_token', 'token', 'qr_token'] as $secret) {
                    if (isset($fields[$secret])) {
                        $fields[$secret] = 'sha256:'.hash('sha256', $fields[$secret]);
                    }
                }
                if (isset($fields['abilities'])) {
                    $fields['abilities'] = json_decode($fields['abilities'], true, flags: JSON_THROW_ON_ERROR);
                    sort($fields['abilities']);
                }
                ksort($fields);
                return $fields;
            })->all();
        }
        return $state;
    }

    private function lookup(string $table): int
    {
        return (int) DB::table($table)->value('id');
    }

    private function action(string $code): int
    {
        return (int) DB::table('processing_actions')->where('action_code', $code)->value('id');
    }

    // Fixture schema and lookups copied from DocumentWorkflowAuditTest; no migrations run.
    private function seedLookups(): void
    {
        $now = now();
        DB::table('document_types')->insert([
            'type_name' => 'Memorandum',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach (['Pending', 'Forwarded', 'Received'] as $status) {
            DB::table('document_statuses')->insert([
                'status_name' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('priorities')->insert([
            'priority_name' => 'Normal',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('confidentiality_levels')->insert([
            'level_name' => 'Public',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('route_actions')->insert([
            'action_name' => 'Forward',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            ['REGISTERED', 'Registered'],
            ['AWAITING_RECEIPT', 'Awaiting Receipt'],
            ['FOR_ACTION', 'For Action'],
            ['UNDER_REVIEW', 'Under Review'],
        ] as [$code, $name]) {
            DB::table('processing_actions')->insert([
                'action_code' => $code,
                'action_name' => $name,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('office_name', 150);
            $table->string('office_code', 20)->unique();
            $table->text('description')->nullable();
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
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('type_name', 100);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('document_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('status_name', 50);
            $table->string('color', 20)->nullable();
            $table->timestamps();
        });
        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('priority_name', 30);
            $table->timestamps();
        });
        Schema::create('confidentiality_levels', function (Blueprint $table) {
            $table->id();
            $table->string('level_name', 50);
            $table->timestamps();
        });
        Schema::create('processing_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_code', 50);
            $table->string('action_name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('route_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_name', 50);
            $table->text('description')->nullable();
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
        Schema::create('document_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('qr_token')->unique();
            $table->string('status', 20)->default('unused');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('registered_at')->nullable();
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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module', 100);
            $table->string('action', 100);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });
    }
}
