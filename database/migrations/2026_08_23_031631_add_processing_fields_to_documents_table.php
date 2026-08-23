<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {

            $table->foreignId(
                'current_action_id'
            )
                ->nullable()
                ->after('current_office_id')
                ->constrained(
                    'processing_actions'
                )
                ->nullOnDelete();

            $table->text(
                'processing_note'
            )
                ->nullable()
                ->after(
                    'current_action_id'
                );

            $table->foreignId(
                'current_action_updated_by'
            )
                ->nullable()
                ->after(
                    'processing_note'
                )
                ->constrained(
                    'users'
                )
                ->nullOnDelete();

            $table->timestamp(
                'current_action_updated_at'
            )
                ->nullable()
                ->after(
                    'current_action_updated_by'
                );
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {

            $table->dropConstrainedForeignId(
                'current_action_updated_by'
            );

            $table->dropColumn([
                'current_action_updated_at',
                'processing_note',
            ]);

            $table->dropConstrainedForeignId(
                'current_action_id'
            );
        });
    }
};