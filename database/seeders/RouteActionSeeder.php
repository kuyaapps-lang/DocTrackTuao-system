<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RouteActionSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('route_actions')->insert([
            ['action_name'=>'Forward'],
            ['action_name'=>'Receive'],
            ['action_name'=>'Return'],
            ['action_name'=>'Approve'],
            ['action_name'=>'Reject'],
            ['action_name'=>'Archive'],
            ['action_name'=>'Complete'],
        ]);
    }
}