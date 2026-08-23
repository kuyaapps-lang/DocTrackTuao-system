<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'document_processing_logs',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'document_id'
                )
                    ->constrained('documents')
                    ->cascadeOnDelete();

                $table->foreignId(
                    'office_id'
                )
                    ->nullable()
                    ->constrained('offices')
                    ->nullOnDelete();

                $table->foreignId(
                    'user_id'
                )
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->foreignId(
                    'processing_action_id'
                )
                    ->nullable()
                    ->constrained('processing_actions')
                    ->nullOnDelete();

                $table->foreignId(
                    'document_route_id'
                )
                    ->nullable()
                    ->constrained('document_routes')
                    ->nullOnDelete();

                $table->string(
                    'event_type',
                    50
                );

                $table->text(
                    'processing_note'
                )->nullable();

                $table->string(
                    'event_note',
                    1000
                )->nullable();

                $table->timestamps();

                $table->index([
                    'document_id',
                    'created_at',
                ]);

                $table->index(
                    'event_type'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'document_processing_logs'
        );
    }
};