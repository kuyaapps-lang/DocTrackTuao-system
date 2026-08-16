<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RouteActionSeeder extends Seeder
{
    public function run(): void
    {
        $actions = [
            'Forward',
            'Receive',
            'Return',
            'Approve',
            'Reject',
            'Archive',
            'Complete',
        ];

        foreach ($actions as $action) {
            DB::table('route_actions')->updateOrInsert(
                ['action_name' => $action],
                ['action_name' => $action]
            );
        }
    }
}