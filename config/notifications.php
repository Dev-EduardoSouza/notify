<?php

declare(strict_types=1);

return [

    'rate_limit_per_minute' => (int) env('RATE_LIMIT_PER_MINUTE', 60),
    'max_body_bytes' => (int) env('MAX_BODY_BYTES', 262144),
    'delivery_retention_days' => (int) env('DELIVERY_RETENTION_DAYS', 30),
    'docs_enabled' => (bool) env('DOCS_ENABLED', true),

    'channels' => [
        'telegram' => [
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
            'recipients' => env('TELEGRAM_RECIPIENTS', ''),
        ],
    ],

    'notion' => [
        'token' => env('NOTION_TOKEN'),
        'api_base' => env('NOTION_API_BASE', 'https://api.notion.com'),
        'version' => env('NOTION_VERSION', '2022-06-28'),
    ],

    'linkedin_drafts' => [
        'parent_page_id' => env('LINKEDIN_PARENT_PAGE_ID'),
        'recipient' => env('LINKEDIN_DRAFTS_RECIPIENT', 'eduardo'),
        'channel' => env('LINKEDIN_DRAFTS_CHANNEL', 'telegram'),
        'timezone' => env('LINKEDIN_DRAFTS_TIMEZONE', 'America/Bahia'),
    ],

];
