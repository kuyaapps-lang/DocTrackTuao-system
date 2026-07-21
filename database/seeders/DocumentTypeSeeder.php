<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('document_types')->insert([
            ['type_name' => 'Memorandum'],
            ['type_name' => 'Office Order'],
            ['type_name' => 'Letter'],
            ['type_name' => 'Purchase Request'],
            ['type_name' => 'Purchase Order'],
            ['type_name' => 'Disbursement Voucher'],
            ['type_name' => 'Payroll'],
            ['type_name' => 'Contract'],
            ['type_name' => 'Report'],
            ['type_name' => 'Others'],
        ]);
    }
}