<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_type_route_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained('document_types')->cascadeOnDelete();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['document_type_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_type_route_steps');
    }
};
