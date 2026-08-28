<?php

return [
    'seeded_accounts' => [
        'administrator' => [
            'password' => env('DEV_SEED_ADMINISTRATOR_PASSWORD'),
        ],
        'records_officer' => [
            'password' => env('DEV_SEED_RECORDS_OFFICER_PASSWORD'),
        ],
        'office_user' => [
            'password' => env('DEV_SEED_OFFICE_USER_PASSWORD'),
        ],
        'viewer' => [
            'password' => env('DEV_SEED_VIEWER_PASSWORD'),
        ],
    ],
];
