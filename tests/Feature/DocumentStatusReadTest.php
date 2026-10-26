<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentStatusReadTest extends TestCase
{
    public function test_only_administrator_can_read_existing_statuses(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('document_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('status_name');
            $table->string('color')->nullable();
        });
        DB::table('document_statuses')->insert(['status_name' => 'Pending', 'color' => 'yellow']);

        $this->getJson('/api/document-statuses')->assertUnauthorized();
        foreach (['Records Officer', 'Office User', 'Viewer'] as $roleName) {
            $this->actAsRole($roleName);
            $this->getJson('/api/document-statuses')->assertForbidden();
        }
        $this->actAsRole('Administrator');
        $this->getJson('/api/document-statuses')->assertOk()->assertExactJson([
            ['id' => 1, 'status_name' => 'Pending', 'color' => 'yellow'],
        ]);
        $this->assertSame(1, DB::table('document_statuses')->count());
    }

    public function test_create_requires_administrator_and_records_audit(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('processing_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_code')->unique();
            $table->string('action_name');
            $table->boolean('is_active');
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
        });
        Schema::create('document_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('status_name')->unique();
            $table->string('color')->nullable();
            $table->unsignedBigInteger('processing_action_id')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('module');
            $table->string('action');
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('current_action_id')->nullable();
        });
        Schema::create('document_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('status_id')->nullable();
        });
        Schema::create('document_processing_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('processing_action_id')->nullable();
        });
        $payload = ['status_name' => 'For Review', 'color' => 'blue'];
        $this->postJson('/api/document-statuses', $payload)->assertUnauthorized();
        foreach (['Records Officer', 'Office User', 'Viewer'] as $roleName) {
            $this->actAsRole($roleName);
            $this->postJson('/api/document-statuses', $payload)->assertForbidden();
        }
        $this->actAsRole('Administrator');
        $this->postJson('/api/document-statuses', ['status_name' => '', 'color' => 'invalid'])->assertUnprocessable();
        $this->assertSame(0, DB::table('document_statuses')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->postJson('/api/document-statuses', $payload)->assertCreated()->assertJsonPath('document_status.status_name', 'For Review');
        $this->postJson('/api/document-statuses', $payload)->assertUnprocessable();
        $this->assertDatabaseHas('document_statuses', $payload);
        $actionId = DB::table('document_statuses')->value('processing_action_id');
        $this->assertNotNull($actionId);
        $this->assertDatabaseHas('processing_actions', ['id' => $actionId, 'action_name' => 'For Review', 'is_active' => true]);
        $this->getJson('/api/processing-actions')->assertOk()->assertJsonPath('data.0.action_name', 'For Review');
        $this->assertDatabaseHas('audit_logs', ['module' => 'master_data', 'action' => 'document_status_created', 'record_id' => 1, 'user_id' => 1]);
        $this->assertSame(1, DB::table('document_statuses')->count());
        $this->assertSame(1, DB::table('audit_logs')->count());

        $this->actAsRole('Viewer');
        $this->putJson('/api/document-statuses/1', ['status_name' => 'Renamed', 'color' => 'green'])->assertForbidden();
        $this->deleteJson('/api/document-statuses/1')->assertForbidden();
        $this->actAsRole('Administrator');
        $this->putJson('/api/document-statuses/1', ['status_name' => 'Renamed', 'color' => 'green'])->assertOk();
        $this->assertDatabaseHas('processing_actions', ['id' => $actionId, 'action_name' => 'Renamed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_status_updated', 'record_id' => 1]);
        DB::table('document_processing_logs')->insert(['processing_action_id' => $actionId]);
        $this->deleteJson('/api/document-statuses/1')->assertConflict();
        $this->putJson('/api/document-statuses/1', ['status_name' => 'Changed again', 'color' => 'blue'])->assertConflict();
        $this->assertDatabaseHas('document_statuses', ['id' => 1, 'status_name' => 'Renamed']);
        DB::table('document_processing_logs')->delete();
        $this->deleteJson('/api/document-statuses/1')->assertOk();
        $this->assertDatabaseMissing('document_statuses', ['id' => 1]);
        $this->assertDatabaseMissing('processing_actions', ['id' => $actionId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_status_deleted', 'record_id' => 1]);
        DB::table('document_statuses')->insert(['id' => 2, 'status_name' => 'Pending']);
        $this->deleteJson('/api/document-statuses/2')->assertConflict();
    }

    private function actAsRole(string $name): void
    {
        $user = new User();
        $user->id = 1;
        $user->office_id = 1;
        $user->setRelation('role', new Role(['name' => $name]));
        Sanctum::actingAs($user);
    }
}
