<?php

namespace Database\Seeders;

use App\Models\ProcessingAction;
use Illuminate\Database\Seeder;

class ProcessingActionSeeder extends Seeder
{
    public function run(): void
    {
        $actions = [

            [
                'action_code' => 'REGISTERED',
                'action_name' => 'Registered',
                'sort_order' => 10,
            ],

            [
                'action_code' => 'AWAITING_RECEIPT',
                'action_name' => 'Awaiting Receipt',
                'sort_order' => 20,
            ],

            [
                'action_code' => 'FOR_ACTION',
                'action_name' => 'For Action',
                'sort_order' => 25,
            ],

            [
                'action_code' => 'FOR_REVIEW',
                'action_name' => 'For Review',
                'sort_order' => 30,
            ],

            [
                'action_code' => 'FOR_SIGNATURE',
                'action_name' => 'For Signature',
                'sort_order' => 40,
            ],

            [
                'action_code' => 'FOR_APPROVAL',
                'action_name' => 'For Approval',
                'sort_order' => 50,
            ],

            [
                'action_code' => 'FOR_CERTIFICATION',
                'action_name' => 'For Certification',
                'sort_order' => 60,
            ],

            [
                'action_code' => 'FOR_VERIFICATION',
                'action_name' => 'For Verification',
                'sort_order' => 70,
            ],

            [
                'action_code' => 'FOR_PROCESSING',
                'action_name' => 'For Processing',
                'sort_order' => 80,
            ],

            [
                'action_code' => 'FOR_PAYMENT',
                'action_name' => 'For Payment Processing',
                'sort_order' => 90,
            ],

            [
                'action_code' => 'FOR_CORRECTION',
                'action_name' => 'For Correction',
                'sort_order' => 100,
            ],

            [
                'action_code' => 'AWAITING_SUPPORTING_DOCS',
                'action_name' => 'Awaiting Supporting Documents',
                'sort_order' => 110,
            ],

            [
                'action_code' => 'SIGNED',
                'action_name' => 'Signed',
                'sort_order' => 120,
            ],

            [
                'action_code' => 'APPROVED',
                'action_name' => 'Approved',
                'sort_order' => 130,
            ],

            [
                'action_code' => 'READY_FOR_FORWARDING',
                'action_name' => 'Ready for Forwarding',
                'sort_order' => 140,
            ],

            [
                'action_code' => 'READY_FOR_RELEASE',
                'action_name' => 'Ready for Release',
                'sort_order' => 150,
            ],

            [
                'action_code' => 'OTHER',
                'action_name' => 'Other',
                'sort_order' => 160,
            ],

        ];

        foreach ($actions as $action) {

            ProcessingAction::updateOrCreate(
                [
                    'action_code' =>
                        $action['action_code'],
                ],
                [
                    'action_name' =>
                        $action['action_name'],

                    'sort_order' =>
                        $action['sort_order'],

                    'is_active' =>
                        true,
                ]
            );
        }
    }
}