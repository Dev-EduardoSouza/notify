<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

enum SendOutcome: string
{
    case Sent = 'sent';
    // Temporary problem (rate limit, 5xx, network): try again later.
    case Retry = 'retry';
    // Permanent problem (bad request, blocked bot): retrying will not help.
    case Failed = 'failed';
}
