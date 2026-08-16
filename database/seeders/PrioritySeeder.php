<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PrioritySeeder extends Seeder
{
    public function run(): void
    {
        $priorities = [
            'Low',
            'Normal',
            'High',
            'Urgent',
        ];

        foreach ($priorities as $priority) {
            DB::table('priorities')->updateOrInsert(
                ['priority_name' => $priority],
                ['priority_name' => $priority]
            );
        }
    }
}