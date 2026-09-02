<?php

return [
    'rate_limits' => [
        'tracking' => [
            'max_attempts' => env('PUBLIC_TRACKING_MAX_ATTEMPTS', 30),
            'decay_seconds' => env('PUBLIC_TRACKING_DECAY_SECONDS', 60),
        ],
        'qr' => [
            'max_attempts' => env('PUBLIC_QR_MAX_ATTEMPTS', 30),
            'decay_seconds' => env('PUBLIC_QR_DECAY_SECONDS', 60),
        ],
    ],
];
