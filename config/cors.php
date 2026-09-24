<?php

use App\Support\FrontendOriginResolver;

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => FrontendOriginResolver::origins(
        env('FRONTEND_URLS', 'http://localhost:9000'),
        env('FRONTEND_APP_URL'),
    ),
    // Los despliegues de preview de Vercel (viti-frontend-xxxx.vercel.app)
    // cambian en cada rama. Sin este patrón el navegador bloquea el preflight
    // y la SPA no puede autenticarse. Se activa con FRONTEND_URL_PATTERNS.
    'allowed_origins_patterns' => FrontendOriginResolver::patterns(
        env('FRONTEND_URL_PATTERNS'),
    ),
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
