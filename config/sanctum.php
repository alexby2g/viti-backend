<?php

use App\Support\FrontendOriginResolver;
use Laravel\Sanctum\Sanctum;

$frontendOrigins = FrontendOriginResolver::origins(
    env('FRONTEND_URLS'),
    env('FRONTEND_APP_URL'),
);

$statefulDomains = FrontendOriginResolver::statefulDomains(
    env('SANCTUM_STATEFUL_DOMAINS'),
    $frontendOrigins,
    array_values(array_filter([
        'localhost',
        'localhost:9000',
        '127.0.0.1',
        '127.0.0.1:9000',
        '::1',
        Sanctum::currentApplicationUrlWithPort(),
    ])),
);

return [
    'stateful' => $statefulDomains,
    'guard' => ['web'],
    'expiration' => env('SANCTUM_EXPIRATION', 1440),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'viti_'),
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
