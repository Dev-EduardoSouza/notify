<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

enum SendOutcome: string
{
    case Sent = 'sent';
    case Retry = 'retry';
    case Failed = 'failed';
}
