<?php

$publicDriver = env('PUBLIC_FILESYSTEM_DRIVER', 'local');
$privateDriver = env('PRIVATE_FILESYSTEM_DRIVER', 'local');

$s3Base = [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'auto'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', true),
    // En producción no debemos fingir que una subida funcionó cuando R2 la rechazó.
    'throw' => true,
    'report' => true,
];

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Fotos y logotipos: bucket R2 público en producción.
        'public' => $publicDriver === 's3' ? array_merge($s3Base, [
            'bucket' => env('AWS_PUBLIC_BUCKET'),
            'url' => env('AWS_PUBLIC_URL'),
        ]) : [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Documentos y anexos: bucket R2 SIN acceso público.
        'private_uploads' => $privateDriver === 's3' ? array_merge($s3Base, [
            'bucket' => env('AWS_PRIVATE_BUCKET'),
        ]) : [
            'driver' => 'local',
            'root' => storage_path('app/private/uploads'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
