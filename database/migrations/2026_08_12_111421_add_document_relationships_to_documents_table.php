<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {

            $table->foreignId('document_type_id')
                ->nullable()
                ->after('description')
                ->constrained('document_types')
                ->nullOnDelete();

            $table->foreignId('status_id')
                ->nullable()
                ->after('document_type_id')
                ->constrained('document_statuses')
                ->nullOnDelete();

            $table->foreignId('priority_id')
                ->nullable()
                ->after('status_id')
                ->constrained('priorities')
                ->nullOnDelete();

            $table->foreignId('confidentiality_level_id')
                ->nullable()
                ->after('priority_id')
                ->constrained('confidentiality_levels')
                ->nullOnDelete();

            $table->foreignId('origin_office_id')
                ->nullable()
                ->after('confidentiality_level_id')
                ->constrained('offices')
                ->nullOnDelete();

            $table->foreignId('current_office_id')
                ->nullable()
                ->after('origin_office_id')
                ->constrained('offices')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->after('current_office_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->date('document_date')
                ->nullable()
                ->after('created_by');

            $table->date('due_date')
                ->nullable()
                ->after('document_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {

            $table->dropForeign(['document_type_id']);
            $table->dropForeign(['status_id']);
            $table->dropForeign(['priority_id']);
            $table->dropForeign(['confidentiality_level_id']);
            $table->dropForeign(['origin_office_id']);
            $table->dropForeign(['current_office_id']);
            $table->dropForeign(['created_by']);

            $table->dropColumn([
                'document_type_id',
                'status_id',
                'priority_id',
                'confidentiality_level_id',
                'origin_office_id',
                'current_office_id',
                'created_by',
                'document_date',
                'due_date',
            ]);
        });
    }
};