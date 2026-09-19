<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('documents', 'tracking_no')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->string('tracking_no', 50)
                ->unique()
                ->after('id');
        });
    }

    public function down(): void
    {
        // tracking_no now belongs to the base documents schema.
    }
};
