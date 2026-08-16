<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConfidentialityLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            'Public',
            'Confidential',
            'Restricted',
        ];

        foreach ($levels as $level) {
            DB::table('confidentiality_levels')->updateOrInsert(
                ['level_name' => $level],
                ['level_name' => $level]
            );
        }
    }
}