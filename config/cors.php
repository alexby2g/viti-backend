<?php

use App\Support\FrontendOriginResolver;

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => FrontendOriginResolver::origins(
        env('FRONTEND_URLS', 'http://localhost:9000'),
        env('FRONTEND_APP_URL'),
    ),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [
        'X-VITI-Request-ID',
        'X-VITI-Client-Request-ID',
        'X-VITI-Duration-Ms',
        'X-VITI-Backend-Version',
    ],
    'max_age' => 600,
    'supports_credentials' => true,
];
