<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_routes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();

            $table->foreignId('from_office_id')
                ->constrained('offices')
                ->restrictOnDelete();

            $table->foreignId('to_office_id')
                ->constrained('offices')
                ->restrictOnDelete();

            $table->foreignId('forwarded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('received_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('forwarded_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->foreignId('status_id')
                ->constrained('document_statuses')
                ->restrictOnDelete();

            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_routes');
    }
};