<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_qr_codes', function (Blueprint $table) {
            $table->foreignId('qr_code_request_id')
                ->nullable()
                ->after('document_id')
                ->constrained('qr_code_requests')
                ->nullOnDelete();
            $table->foreignId('assigned_office_id')
                ->nullable()
                ->after('qr_code_request_id')
                ->constrained('offices')
                ->nullOnDelete();

            $table->index(['assigned_office_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('document_qr_codes', function (Blueprint $table) {
            $table->dropIndex(['assigned_office_id', 'status']);
            $table->dropConstrainedForeignId('assigned_office_id');
            $table->dropConstrainedForeignId('qr_code_request_id');
        });
    }
};
