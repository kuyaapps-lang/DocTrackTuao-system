<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('designation', 150)
                ->nullable()
                ->after('username');
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->string('signatory_designation', 150)
                ->nullable()
                ->after('signatory_name');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn('signatory_designation');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('designation');
        });
    }
};
