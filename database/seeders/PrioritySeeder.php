<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PrioritySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('priorities')->insert([
            ['priority_name'=>'Low'],
            ['priority_name'=>'Normal'],
            ['priority_name'=>'High'],
            ['priority_name'=>'Urgent'],
        ]);
    }
}