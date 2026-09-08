<?php

return [
    'paths' => ['api/*'],
    // The SPA and API share an origin. No cross-origin browser access is granted.
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
