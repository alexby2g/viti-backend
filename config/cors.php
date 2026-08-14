<?php
return [
    'paths'=>['api/*','sanctum/csrf-cookie'],
    'allowed_methods'=>['*'],
    'allowed_origins'=>array_filter(explode(',',env('FRONTEND_URLS','http://localhost:9000'))),
    'allowed_origins_patterns'=>[],
    'allowed_headers'=>['*'],
    'exposed_headers'=>[
        'X-VITI-Request-ID',
        'X-VITI-Client-Request-ID',
        'X-VITI-Duration-Ms',
        'X-VITI-Backend-Version',
    ],
    'max_age'=>600,
    'supports_credentials'=>true,
];
