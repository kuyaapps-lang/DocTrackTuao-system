<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_actions', function (Blueprint $table) {
            $table->id();

            $table->string(
                'action_code',
                50
            )->unique();

            $table->string(
                'action_name',
                100
            );

            $table->boolean(
                'is_active'
            )->default(true);

            $table->unsignedSmallInteger(
                'sort_order'
            )->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'processing_actions'
        );
    }
};