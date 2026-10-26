<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table): void {
            $table->string('request_type', 30)->default('reset')->after('message');
            $table->string('requested_password_hash')->nullable()->after('request_type');
            $table->index(['user_id', 'request_type', 'status'], 'password_change_request_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table): void {
            $table->dropIndex('password_change_request_lookup');
            $table->dropColumn(['request_type', 'requested_password_hash']);
        });
    }
};
