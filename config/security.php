<?php

return [
    'trusted_hosts' => env('TRUSTED_HOSTS', 'localhost,127.0.0.1,::1'),
    'hsts_enabled' => env('SECURITY_HSTS_ENABLED', false),
    'vite_dev_server_url' => env('VITE_DEV_SERVER_URL'),
];
