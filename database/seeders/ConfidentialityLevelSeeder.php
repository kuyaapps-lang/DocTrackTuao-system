<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConfidentialityLevelSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('confidentiality_levels')->insert([
            ['level_name'=>'Public'],
            ['level_name'=>'Confidential'],
            ['level_name'=>'Restricted'],
        ]);
    }
}