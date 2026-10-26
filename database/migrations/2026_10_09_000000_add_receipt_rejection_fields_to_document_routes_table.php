<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_routes', function (Blueprint $table): void {
            $table->foreignId('rejected_by')
                ->nullable()
                ->after('received_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('received_at');
            $table->text('rejection_reason')->nullable()->after('cancellation_reason');
            $table->foreignId('return_route_id')
                ->nullable()
                ->after('rejection_reason')
                ->constrained('document_routes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_routes', function (Blueprint $table): void {
            $table->dropForeign(['rejected_by']);
            $table->dropForeign(['return_route_id']);
            $table->dropColumn([
                'rejected_by',
                'rejected_at',
                'rejection_reason',
                'return_route_id',
            ]);
        });
    }
};
