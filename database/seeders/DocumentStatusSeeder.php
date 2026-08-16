<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['status_name' => 'Draft', 'color' => 'gray'],
            ['status_name' => 'Pending', 'color' => 'yellow'],
            ['status_name' => 'Received', 'color' => 'blue'],
            ['status_name' => 'Forwarded', 'color' => 'indigo'],
            ['status_name' => 'Returned', 'color' => 'orange'],
            ['status_name' => 'Approved', 'color' => 'green'],
            ['status_name' => 'Completed', 'color' => 'emerald'],
            ['status_name' => 'Archived', 'color' => 'slate'],
            ['status_name' => 'Cancelled', 'color' => 'red'],
        ];

        foreach ($statuses as $status) {
            DB::table('document_statuses')->updateOrInsert(
                ['status_name' => $status['status_name']],
                ['color' => $status['color']]
            );
        }
    }
}