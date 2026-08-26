<?php

return [
    'transactional_mail' => [
        'provider' => env('VITI_MAIL_PROVIDER', 'laravel'),
    ],

    'brevo' => [
        'api_url' => env('BREVO_API_URL', 'https://api.brevo.com/v3'),
        'api_key' => env('BREVO_API_KEY'),
        'from_email' => env('BREVO_FROM_EMAIL', env('MAIL_FROM_ADDRESS', 'no-reply@viti.local')),
        'from_name' => env('BREVO_FROM_NAME', env('MAIL_FROM_NAME', 'AGR Studio · VITI')),
        'reply_to_email' => env('BREVO_REPLY_TO_EMAIL'),
        'reply_to_name' => env('BREVO_REPLY_TO_NAME', env('MAIL_FROM_NAME', 'AGR Studio · VITI')),
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'voice_id' => env('ELEVENLABS_VOICE_ID', 'ZKOQuf0oY2dzqs2f3sdk'),
        'model_id' => env('ELEVENLABS_MODEL_ID', 'eleven_multilingual_v2'),
        'output_format' => env('ELEVENLABS_OUTPUT_FORMAT', 'mp3_44100_128'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'frontend_url' => env('VITI_FRONTEND_URL'),
    ],
];
