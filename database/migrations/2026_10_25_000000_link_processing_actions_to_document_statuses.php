<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $actions = DB::table('processing_actions')->orderBy('id')->get(['id', 'action_name']);
        $seen = [];
        foreach ($actions as $action) {
            $name = trim($action->action_name);
            $key = mb_strtolower($name);
            if ($name === '' || mb_strlen($name) > 50 || isset($seen[$key])) {
                throw new RuntimeException('Processing action names must be distinct and fit the status name column before migration.');
            }
            $seen[$key] = true;
        }

        Schema::table('document_statuses', function (Blueprint $table) {
            $table->foreignId('processing_action_id')->nullable()->unique()
                ->constrained('processing_actions')->restrictOnDelete();
        });

        DB::transaction(function () use ($actions) {
            foreach ($actions as $action) {
                $name = trim($action->action_name);
                $status = DB::table('document_statuses')->whereRaw('LOWER(status_name) = ?', [mb_strtolower($name)])->first();
                if ($status) {
                    DB::table('document_statuses')->where('id', $status->id)->update(['processing_action_id' => $action->id]);
                } else {
                    DB::table('document_statuses')->insert([
                        'status_name' => $name,
                        'processing_action_id' => $action->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            $systemStatuses = ['draft', 'pending', 'received', 'forwarded', 'returned', 'approved', 'completed', 'archived', 'cancelled'];
            foreach (DB::table('document_statuses')->whereNull('processing_action_id')->get() as $status) {
                if (in_array(mb_strtolower(trim($status->status_name)), $systemStatuses, true)) {
                    continue;
                }
                $actionId = DB::table('processing_actions')->insertGetId([
                    'action_code' => 'CUSTOM_'.str_replace('-', '', (string) Str::uuid()),
                    'action_name' => $status->status_name,
                    'is_active' => true,
                    'sort_order' => 65535,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('document_statuses')->where('id', $status->id)->update(['processing_action_id' => $actionId]);
            }
        });
    }

    public function down(): void
    {
        // Keep reference rows: they may have been used after this migration.
        Schema::table('document_statuses', function (Blueprint $table) {
            $table->dropForeign(['processing_action_id']);
            $table->dropUnique(['processing_action_id']);
            $table->dropColumn('processing_action_id');
        });
    }
};
