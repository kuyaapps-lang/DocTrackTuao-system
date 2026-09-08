<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Process9D2IMasterDataSecurityTest extends TestCase
{
    private array $tables = ['departments', 'offices', 'document_types', 'priorities',
        'confidentiality_levels', 'roles', 'users', 'documents', 'document_routes',
        'document_processing_logs', 'document_qr_codes', 'document_attachments', 'audit_logs',
        'personal_access_tokens'];
    private int $officeId;
    private int $typeId;
    private int $departmentId;
    private int $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->schema();
        $this->departmentId = DB::table('departments')->insertGetId([
            'department_name' => 'Department', 'internal' => 'sensitive-marker',
        ]);
        $this->officeId = DB::table('offices')->insertGetId([
            'department_id' => $this->departmentId, 'office_name' => 'Office',
            'office_code' => 'OFF', 'description' => 'Office description', 'internal' => 'sensitive-marker',
        ]);
        $this->typeId = DB::table('document_types')->insertGetId([
            'type_name' => 'Letter', 'description' => 'Type description', 'internal' => 'sensitive-marker',
        ]);
        DB::table('priorities')->insert(['priority_name' => 'Normal', 'internal' => 'sensitive-marker']);
        DB::table('confidentiality_levels')->insert(['level_name' => 'Public', 'internal' => 'sensitive-marker']);
        foreach (['Administrator', 'Records Officer', 'Office User', 'Viewer'] as $role) {
            Role::create(['name' => $role]);
        }
        foreach (['documents', 'document_routes', 'document_processing_logs',
            'document_qr_codes', 'document_attachments', 'audit_logs'] as $table) {
            DB::table($table)->insert(['internal' => 'sensitive-marker']);
        }
        DB::table('personal_access_tokens')->insert([
            'name' => 'sentinel', 'token' => hash('sha256', 'sensitive-marker'),
            'tokenable_id' => 999, 'tokenable_type' => User::class, 'abilities' => '["*"]',
        ]);
        Log::spy();
    }

    #[DataProvider('roles')]
    public function test_role_reads_have_exact_allowlisted_shapes(string $role): void
    {
        $this->actingRole($role);
        $before = $this->snapshot();
        $office = ['id' => $this->officeId, 'department_id' => $this->departmentId,
            'office_name' => 'Office', 'office_code' => 'OFF', 'description' => 'Office description',
            'department' => ['department_name' => 'Department']];
        $type = ['id' => $this->typeId, 'type_name' => 'Letter', 'description' => 'Type description'];
        foreach (['/api/offices' => [$office], '/api/offices/'.$this->officeId => $office,
            '/api/document-types' => [$type], '/api/document-types/'.$this->typeId => $type] as $url => $shape) {
            $response = $this->getJson($url)->assertOk()->assertExactJson($shape);
            $this->safe($response);
        }
        $options = $this->getJson('/api/document-form-options');
        if (in_array($role, ['Administrator', 'Records Officer'], true)) {
            $options->assertOk()->assertExactJson([
                'document_types' => [['id' => $this->typeId, 'type_name' => 'Letter']],
                'offices' => [['id' => $this->officeId, 'office_name' => 'Office', 'office_code' => 'OFF']],
                'priorities' => [['id' => DB::table('priorities')->value('id'), 'priority_name' => 'Normal']],
                'confidentiality_levels' => [['id' => DB::table('confidentiality_levels')->value('id'), 'level_name' => 'Public']],
            ]);
        } else {
            $options->assertForbidden();
        }
        $this->safe($options);
        $this->assertSame($before, $this->snapshot());
    }

    public static function roles(): iterable
    {
        foreach (['Administrator', 'Records Officer', 'Office User', 'Viewer'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('deniedRoles')]
    public function test_mutations_are_forbidden_before_validation_or_lookup(?string $role): void
    {
        if ($role !== null) {
            $this->actingRole($role);
        }
        foreach (['offices' => $this->officeId, 'document-types' => $this->typeId] as $resource => $id) {
            foreach (['POST' => '/api/'.$resource, 'PUT' => '/api/'.$resource.'/'.$id,
                'PATCH' => '/api/'.$resource.'/malformed', 'DELETE' => '/api/'.$resource.'/'.$id] as $method => $url) {
                $this->rejected($method, $url, ['unsupported' => 'sensitive-marker'], $role === null ? 401 : 403);
            }
        }
        if ($role === null) {
            foreach (['/api/offices', '/api/offices/'.$this->officeId, '/api/document-types',
                '/api/document-types/'.$this->typeId, '/api/document-form-options'] as $url) {
                $this->rejected('GET', $url, [], 401);
            }
        }
    }

    public static function deniedRoles(): iterable
    {
        foreach (['Records Officer', 'Office User', 'Viewer', null] as $role) {
            yield $role ?? 'unauthenticated' => [$role];
        }
    }

    public function test_administrator_crud_preserves_messages_trimming_and_exact_audits(): void
    {
        $this->actingRole('Administrator');
        $before = $this->snapshot();
        foreach (['offices', 'document-types'] as $resource) {
            $field = $resource === 'offices' ? 'office' : 'document_type';
            $label = $resource === 'offices' ? 'Office' : 'Document type';
            $payload = $resource === 'offices'
                ? ['office_name' => ' New office ', 'office_code' => ' NEW ', 'department_id' => null, 'description' => '   ']
                : ['type_name' => ' New type ', 'description' => '   '];
            $auditCount = DB::table('audit_logs')->count();
            $created = $this->postJson('/api/'.$resource, $payload)->assertCreated();
            $id = $created->json($field.'.id');
            $this->assertAudit($auditCount, $field.'_created', $label.' created.', $id);
            $expected = $resource === 'offices'
                ? ['id' => $id, 'office_name' => 'New office', 'office_code' => 'NEW', 'department_id' => null, 'description' => null, 'department' => null]
                : ['id' => $id, 'type_name' => 'New type', 'description' => null];
            $created->assertExactJson(['message' => $label.' created successfully', $field => $expected]);
            $this->safe($created);
            $payload['description'] = ' Updated description ';
            foreach (['PUT', 'PATCH'] as $method) {
                $expected['description'] = 'Updated description';
                $auditCount = DB::table('audit_logs')->count();
                $response = $this->json($method, '/api/'.$resource.'/'.$id, $payload)->assertOk()
                    ->assertExactJson(['message' => $label.' updated successfully', $field => $expected]);
                $this->assertAudit($auditCount, $field.'_updated', $label.' updated.', $id);
                $this->safe($response);
            }
            $auditCount = DB::table('audit_logs')->count();
            $deleted = $this->deleteJson('/api/'.$resource.'/'.$id)->assertOk()
                ->assertExactJson(['message' => $label.' deleted successfully']);
            $this->assertAudit($auditCount, $field.'_deleted', $label.' deleted.', $id);
            $this->safe($deleted);
        }
        $after = $this->snapshot();
        $this->assertSame($before['audit_logs'], array_slice($after['audit_logs'], 0, count($before['audit_logs'])));
        unset($before['audit_logs'], $after['audit_logs']);
        $this->assertSame($before, $after);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_create_and_update_preserve_every_row(string $resource, array $bad): void
    {
        $this->actingRole('Administrator');
        $base = $resource === 'offices' ? ['office_name' => 'New', 'office_code' => 'NEW'] : ['type_name' => 'New'];
        $id = $resource === 'offices' ? $this->officeId : $this->typeId;
        // Update a second record so duplicate tests exercise a real collision.
        $other = $resource === 'offices' ? ['office_name' => 'Other', 'office_code' => 'OTHER'] : ['type_name' => 'Other'];
        $otherId = DB::table(str_replace('-', '_', $resource))->insertGetId($other);
        foreach (['POST' => '/api/'.$resource, 'PUT' => '/api/'.$resource.'/'.$otherId,
            'PATCH' => '/api/'.$resource.'/'.$otherId] as $method => $url) {
            $this->rejected($method, $url, array_replace($base, $bad), 422);
        }
        $this->rejected('DELETE', '/api/'.$resource.'/'.$id, ['unsupported' => 'sensitive-marker'], 422);
    }

    public static function invalidPayloads(): iterable
    {
        foreach (['offices' => ['office_name', 150], 'document-types' => ['type_name', 100]] as $resource => [$field, $limit]) {
            foreach (['empty' => '', 'whitespace' => " \t ", 'null' => null, 'array' => [],
                'number' => 123, 'boolean' => true, 'overlength' => str_repeat('a', $limit + 1)] as $case => $value) {
                yield $resource.' '.$case => [$resource, [$field => $value]];
            }
            yield $resource.' unknown' => [$resource, ['sensitive-marker' => 'sensitive-marker']];
            yield $resource.' description array' => [$resource, ['description' => []]];
            yield $resource.' description length' => [$resource, ['description' => str_repeat('a', 65536)]];
            yield $resource.' description bytes' => [$resource, ['description' => str_repeat('é', 32768)]];
        }
        yield 'duplicate type' => ['document-types', ['type_name' => ' Letter ']];
        yield 'duplicate office code' => ['offices', ['office_code' => ' OFF ']];
        foreach (['', [], true, str_repeat('a', 21)] as $value) {
            yield ['offices', ['office_code' => $value]];
        }
        foreach ([0, -1, 99999, 'not-an-id', [], 1.5, true] as $value) {
            yield ['offices', ['department_id' => $value]];
        }
    }

    public function test_schema_length_boundaries_foreign_reference_and_optional_update_fields(): void
    {
        $this->actingRole('Administrator');
        $before = $this->snapshot();
        $description = str_repeat('a', 65535);
        $auditCount = DB::table('audit_logs')->count();
        $office = $this->postJson('/api/offices', [
            'office_name' => str_repeat('n', 150), 'office_code' => str_repeat('c', 20),
            'department_id' => (string) $this->departmentId, 'description' => $description,
        ])->assertCreated();
        $id = $office->json('office.id');
        $this->assertAudit($auditCount, 'office_created', 'Office created.', $id);
        $office->assertJsonPath('office.department_id', $this->departmentId)
            ->assertJsonPath('office.department.department_name', 'Department');
        $this->safe($office);
        $auditCount = DB::table('audit_logs')->count();
        $updated = $this->putJson('/api/offices/'.$id, [
            'office_name' => 'Office', 'office_code' => str_repeat('c', 20),
        ])->assertOk()->assertJsonPath('office.description', $description)
            ->assertJsonPath('office.department_id', $this->departmentId);
        $this->assertAudit($auditCount, 'office_updated', 'Office updated.', $id);
        $this->safe($updated);
        $auditCount = DB::table('audit_logs')->count();
        $type = $this->postJson('/api/document-types', [
            'type_name' => str_repeat('n', 100), 'description' => $description,
        ])->assertCreated();
        $this->assertAudit($auditCount, 'document_type_created', 'Document type created.', $type->json('document_type.id'));
        $this->safe($type);
        $after = $this->snapshot();
        $this->assertSame($before['audit_logs'], array_slice($after['audit_logs'], 0, count($before['audit_logs'])));
        unset($before['audit_logs'], $after['audit_logs']);
        unset($before['offices'], $before['document_types'], $after['offices'], $after['document_types']);
        $this->assertSame($before, $after);
    }

    public function test_missing_fields_and_invalid_identifiers_have_safe_errors(): void
    {
        $this->actingRole('Administrator');
        foreach (['offices' => $this->officeId, 'document-types' => $this->typeId] as $resource => $id) {
            $this->rejected('POST', '/api/'.$resource, [], 422);
            $this->rejected('PUT', '/api/'.$resource.'/'.$id, [], 422);
            foreach (['0', '-1', '01', '1.0', '1e0', 'sensitive-marker', '999999', str_repeat('9', 30)] as $badId) {
                foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    $this->rejected($method, '/api/'.$resource.'/'.$badId, [], 404);
                }
            }
        }
    }

    #[DataProvider('references')]
    public function test_referenced_deletions_preserve_business_and_audit_data(string $table, string $column, string $resource): void
    {
        $this->actingRole('Administrator');
        $id = $resource === 'offices' ? $this->officeId : $this->typeId;
        DB::table($table)->where('id', DB::table($table)->value('id'))->update([$column => $id]);
        $this->rejected('DELETE', '/api/'.$resource.'/'.$id, [], 409);
    }

    public static function references(): iterable
    {
        foreach (['users' => ['office_id'], 'documents' => ['origin_office_id', 'current_office_id'],
            'document_routes' => ['from_office_id', 'to_office_id'], 'document_processing_logs' => ['office_id']] as $table => $columns) {
            foreach ($columns as $column) {
                yield $table.' '.$column => [$table, $column, 'offices'];
            }
        }
        yield 'document type' => ['documents', 'document_type_id', 'document-types'];
    }

    private function actingRole(string $role): void
    {
        $user = User::forceCreate(['name' => 'Synthetic user', 'role_id' => Role::where('name', $role)->value('id')]);
        $this->actorId = $user->id;
        Sanctum::actingAs($user);
    }

    private function assertAudit(int $beforeCount, string $action, string $description, int $recordId): void
    {
        $this->assertSame($beforeCount + 1, DB::table('audit_logs')->count());
        $audit = DB::table('audit_logs')->orderByDesc('id')->first();
        $this->assertSame([
            'module' => 'master_data', 'action' => $action, 'description' => $description,
            'user_id' => $this->actorId, 'record_id' => $recordId,
        ], ['module' => $audit->module, 'action' => $audit->action, 'description' => $audit->description,
            'user_id' => $audit->user_id, 'record_id' => $audit->record_id]);
        $this->assertNotNull($audit->created_at);
        $this->assertNotNull($audit->updated_at);
    }

    #[DataProvider('auditFailureMutations')]
    public function test_audit_insert_failure_rolls_back_the_entire_mutation(string $resource, string $method, string $action): void
    {
        $this->actingRole('Administrator');
        $id = $resource === 'offices' ? $this->officeId : $this->typeId;
        $payload = $method === 'DELETE' ? [] : ($resource === 'offices'
            ? ['office_name' => 'sensitive-marker', 'office_code' => 'NEW']
            : ['type_name' => 'sensitive-marker']);
        $url = '/api/'.$resource.($method === 'POST' ? '' : '/'.$id);
        $before = $this->snapshot();
        // Fail the actual INSERT in SQLite, exercising the shared logger's null result.
        DB::unprepared("CREATE TEMP TRIGGER fail_master_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'sensitive-marker'); END");
        try {
            $response = $this->json($method, $url, $payload)->assertStatus(500)
                ->assertExactJson(['message' => 'An unexpected error occurred.']);
            $this->assertSame($before, $this->snapshot());
            $this->assertStringNotContainsString('sensitive-marker', $response->getContent());
            Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) use ($action) {
                $this->assertSame('Unable to write audit log.', $message);
                $this->assertSame(['module', 'action', 'record_id', 'exception_class'], array_keys($context));
                $this->assertSame('master_data', $context['module']);
                $this->assertSame($action, $context['action']);
                $this->assertSame(\Illuminate\Database\QueryException::class, $context['exception_class']);
                $this->assertStringNotContainsString('sensitive-marker', json_encode($context));
                return true;
            });
            foreach (['error', 'critical', 'alert', 'emergency'] as $level) {
                Log::shouldNotHaveReceived($level);
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_master_audit');
        }
    }

    public static function auditFailureMutations(): iterable
    {
        foreach (['offices' => 'office', 'document-types' => 'document_type'] as $resource => $prefix) {
            foreach (['POST' => 'created', 'PUT' => 'updated', 'DELETE' => 'deleted'] as $method => $suffix) {
                yield $prefix.'_'.$suffix => [$resource, $method, $prefix.'_'.$suffix];
            }
        }
    }

    private function rejected(string $method, string $url, array $payload, int $status): void
    {
        $before = $this->snapshot();
        $response = $this->json($method, $url, $payload)->assertStatus($status);
        $this->assertSame($before, $this->snapshot());
        $this->safe($response);
    }

    private function safe($response): void
    {
        $serialized = strtolower($response->getContent().json_encode($response->headers->all()));
        foreach (['sensitive-marker', 'sqlstate', 'stack trace', 'password', 'tokenable', 'created_at', 'updated_at'] as $marker) {
            $this->assertStringNotContainsString($marker, $serialized);
        }
        foreach (['error', 'warning', 'critical', 'alert', 'emergency'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->tables as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table) {
                $values = (array) $row;
                if ($table === 'personal_access_tokens') {
                    unset($values['token']);
                }
                ksort($values);
                return $values;
            })->all();
        }
        return $snapshot;
    }

    private function schema(): void
    {
        foreach (['departments' => 'department_name', 'priorities' => 'priority_name',
            'confidentiality_levels' => 'level_name', 'roles' => 'name'] as $name => $column) {
            Schema::create($name, function (Blueprint $table) use ($column) {
                $table->id(); $table->string($column); $table->string('internal')->nullable(); $table->timestamps();
            });
        }
        Schema::create('offices', function (Blueprint $table) {
            $table->id(); $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('office_name', 150); $table->string('office_code', 20)->unique();
            $table->text('description')->nullable(); $table->string('internal')->nullable(); $table->timestamps();
        });
        Schema::create('document_types', function (Blueprint $table) {
            $table->id(); $table->string('type_name', 100)->unique(); $table->text('description')->nullable();
            $table->string('internal')->nullable(); $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->foreignId('role_id')->nullable()->constrained();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete(); $table->timestamps();
        });
        foreach (['documents' => ['origin_office_id', 'current_office_id'],
            'document_routes' => ['from_office_id', 'to_office_id'],
            'document_processing_logs' => ['office_id'], 'document_qr_codes' => [],
            'document_attachments' => [], 'audit_logs' => []] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($name, $columns) {
                $table->id(); $table->string('internal')->nullable(); $table->timestamps();
                foreach ($columns as $column) {
                    $foreign = $table->foreignId($column)->nullable()->constrained('offices');
                    $name === 'document_routes' ? $foreign->restrictOnDelete() : $foreign->nullOnDelete();
                }
                if ($name === 'documents') {
                    $table->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
                }
                if ($name === 'audit_logs') {
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->string('module')->nullable(); $table->string('action')->nullable();
                    $table->unsignedBigInteger('record_id')->nullable();
                    $table->text('description')->nullable(); $table->string('ip_address', 45)->nullable();
                    $table->text('user_agent')->nullable();
                }
            });
        }
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id(); $table->morphs('tokenable'); $table->string('name'); $table->string('token');
            $table->text('abilities')->nullable(); $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable(); $table->timestamps();
        });
        // Each Laravel test application owns and discards this in-memory connection,
        // config, authentication state and logger mock; production routes are unchanged.
    }
}
