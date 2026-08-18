<?php

return [
    'transactional_mail' => [
        // VITI uses Brevo HTTPS API by default in production.
        'provider' => env('VITI_MAIL_PROVIDER', 'brevo'),
    ],

    'brevo' => [
        'api_url' => env('BREVO_API_URL', 'https://api.brevo.com/v3'),
        'api_key' => env('BREVO_API_KEY'),
        'from_email' => env('BREVO_FROM_EMAIL', env('MAIL_FROM_ADDRESS', 'no-reply@viti.local')),
        'from_name' => env('BREVO_FROM_NAME', env('MAIL_FROM_NAME', 'AGR Studio · VITI')),
        'reply_to_email' => env('BREVO_REPLY_TO_EMAIL'),
        'reply_to_name' => env('BREVO_REPLY_TO_NAME', env('MAIL_FROM_NAME', 'AGR Studio · VITI')),
    ],
];
