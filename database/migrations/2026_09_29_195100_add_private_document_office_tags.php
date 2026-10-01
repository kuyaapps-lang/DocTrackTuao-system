<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_office_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['document_id', 'office_id']);
        });
        $private = DB::table('confidentiality_levels')->where('level_name', 'Private')->value('id')
            ?? DB::table('confidentiality_levels')->insertGetId(['level_name' => 'Private', 'created_at' => now(), 'updated_at' => now()]);
        $old = DB::table('confidentiality_levels')->whereIn('level_name', ['Restricted', 'Confidential'])->pluck('id');
        DB::table('documents')->whereIn('confidentiality_level_id', $old)->update(['confidentiality_level_id' => $private]);
        DB::table('confidentiality_levels')->whereIn('id', $old)->delete();
    }
    public function down(): void { Schema::dropIfExists('document_office_tags'); }
};
