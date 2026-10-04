<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_routes', function (Blueprint $table) {
            $table->foreignId('cancelled_by')
                ->nullable()
                ->after('received_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('received_at');
            $table->text('cancellation_reason')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('document_routes', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_by', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
