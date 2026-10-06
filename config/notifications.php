<?php

declare(strict_types=1);

return [

    'api_keys' => (string) env('API_KEYS', ''),

    'rate_limit_per_minute' => (int) env('RATE_LIMIT_PER_MINUTE', 60),
    'max_body_bytes' => (int) env('MAX_BODY_BYTES', 262144),
    'delivery_retention_days' => (int) env('DELIVERY_RETENTION_DAYS', 30),

    'channels' => [
        'telegram' => [
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
            'recipients' => env('TELEGRAM_RECIPIENTS', ''),
        ],
    ],

];
