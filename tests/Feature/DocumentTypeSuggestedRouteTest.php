<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentTypeSuggestedRouteTest extends TestCase
{
    public function test_administrator_can_update_suggestions_without_changing_actual_history(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('document_types', function (Blueprint $table) { $table->id(); $table->string('type_name'); $table->timestamps(); });
        Schema::create('offices', function (Blueprint $table) { $table->id(); $table->string('office_name'); });
        Schema::create('document_type_route_steps', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('document_type_id'); $table->unsignedBigInteger('office_id'); $table->unsignedInteger('position'); $table->timestamps();
        });
        Schema::create('document_routes', function (Blueprint $table) { $table->id(); $table->string('sentinel'); });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id')->nullable(); $table->string('module'); $table->string('action');
            $table->unsignedBigInteger('record_id')->nullable(); $table->text('description')->nullable(); $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable(); $table->timestamps();
        });
        DB::table('document_types')->insert(['id' => 1, 'type_name' => 'Letter']);
        DB::table('offices')->insert([['id' => 1, 'office_name' => 'Mayor'], ['id' => 2, 'office_name' => 'Accounting']]);
        DB::table('document_routes')->insert(['sentinel' => 'actual history']);
        $this->putJson('/api/document-types/1/suggested-route', ['office_ids' => [1, 2]])->assertUnauthorized();
        $this->role('Records Officer');
        $this->putJson('/api/document-types/1/suggested-route', ['office_ids' => [1, 2]])->assertForbidden();
        $this->role('Administrator');
        $this->putJson('/api/document-types/1/suggested-route', ['office_ids' => [1, 999]])->assertUnprocessable();
        $this->assertSame(0, DB::table('document_type_route_steps')->count());
        $this->putJson('/api/document-types/1/suggested-route', ['office_ids' => [1, 2]])->assertOk();
        $this->getJson('/api/document-types/1/suggested-route')->assertOk()->assertExactJson(['office_ids' => [1, 2]]);
        $this->putJson('/api/document-types/1/suggested-route', ['office_ids' => [2, 1]])->assertOk();
        $this->getJson('/api/document-types/1/suggested-route')->assertExactJson(['office_ids' => [2, 1]]);
        $this->assertDatabaseHas('document_routes', ['sentinel' => 'actual history']);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'document_type_route_updated')->count());
        $this->putJson('/api/document-types/1/suggested-route', ['office_ids' => []])->assertOk();
        $this->assertSame(0, DB::table('document_type_route_steps')->count());
    }

    private function role(string $name): void
    {
        $user = new User(); $user->id = 1; $user->office_id = 1;
        $user->setRelation('role', new Role(['name' => $name]));
        Sanctum::actingAs($user);
    }
}
