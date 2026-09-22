<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_code_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('requested_office_id')
                ->nullable()
                ->constrained('offices')
                ->nullOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->text('purpose')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'requested_office_id']);
            $table->index('requested_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_code_requests');
    }
};
