<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'Memorandum',
            'Office Order',
            'Letter',
            'Purchase Request',
            'Purchase Order',
            'Disbursement Voucher',
            'Payroll',
            'Contract',
            'Report',
            'Others',
        ];

        foreach ($types as $type) {
            DB::table('document_types')->updateOrInsert(
                ['type_name' => $type],
                ['type_name' => $type]
            );
        }
    }
}