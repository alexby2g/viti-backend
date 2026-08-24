<?php

return [
    'autopilot' => [
        'enabled' => env('AGR_AUTOPILOT_ENABLED', true),
        'interval_minutes' => (int) env('AGR_AUTOPILOT_INTERVAL', 15),
        'mode' => 'local_safe',
    ],
    'openai' => [
        'enabled' => env('AGR_OPENAI_ENABLED', false),
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('AGR_OPENAI_MODEL', 'gpt-5.6'),
    ],
];
