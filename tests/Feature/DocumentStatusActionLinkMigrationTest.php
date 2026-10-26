<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentStatusActionLinkMigrationTest extends TestCase
{
    public function test_action_links_preserve_existing_status_and_action_records(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('processing_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_name', 100);
            $table->string('action_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('document_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('status_name', 50)->unique();
            $table->string('color')->nullable();
            $table->timestamps();
        });
        DB::table('document_statuses')->insert(['id' => 7, 'status_name' => 'Approved', 'color' => 'green']);
        DB::table('document_statuses')->insert(['id' => 8, 'status_name' => 'Custom Review']);
        DB::table('processing_actions')->insert([
            ['id' => 11, 'action_name' => 'Approved'],
            ['id' => 12, 'action_name' => 'For Review'],
        ]);

        $migration = require database_path('migrations/2026_10_25_000000_link_processing_actions_to_document_statuses.php');
        $migration->up();
        $this->assertDatabaseHas('document_statuses', ['id' => 7, 'status_name' => 'Approved', 'color' => 'green', 'processing_action_id' => 11]);
        $this->assertDatabaseHas('document_statuses', ['status_name' => 'For Review', 'processing_action_id' => 12]);
        $this->assertNotNull(DB::table('document_statuses')->where('id', 8)->value('processing_action_id'));
        $this->assertSame(3, DB::table('processing_actions')->count());
        $migration->down();
        $this->assertFalse(Schema::hasColumn('document_statuses', 'processing_action_id'));
        $this->assertDatabaseHas('document_statuses', ['id' => 7, 'status_name' => 'Approved', 'color' => 'green']);
        $this->assertDatabaseHas('document_statuses', ['status_name' => 'For Review']);
    }

    public function test_invalid_action_names_stop_before_schema_changes(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('processing_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_name', 100);
        });
        Schema::create('document_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('status_name', 50);
        });
        DB::table('processing_actions')->insert(['action_name' => str_repeat('a', 51)]);
        $migration = require database_path('migrations/2026_10_25_000000_link_processing_actions_to_document_statuses.php');
        try {
            $migration->up();
            $this->fail('Expected an invalid action name to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Processing action names', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('document_statuses', 'processing_action_id'));
    }
}
