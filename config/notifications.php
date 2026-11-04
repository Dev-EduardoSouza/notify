<?php

declare(strict_types=1);

return [

    'api_keys' => (string) env('API_KEYS', ''),

    'rate_limit_per_minute' => (int) env('RATE_LIMIT_PER_MINUTE', 30),
    'max_body_bytes' => (int) env('MAX_BODY_BYTES', 262144),

    'channels' => [
        'telegram' => [
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
            'recipients' => env('TELEGRAM_RECIPIENTS', ''),
        ],
        'whatsapp' => [
            'api_base' => env('WHATSAPP_API_BASE', ''),
            'api_key' => env('WHATSAPP_API_KEY'),
            'instance' => env('WHATSAPP_INSTANCE', ''),
            'recipients' => env('WHATSAPP_RECIPIENTS', ''),
            'idle_reset_minutes' => (int) env('WHATSAPP_IDLE_RESET_MINUTES', 30),
        ],
    ],

];
