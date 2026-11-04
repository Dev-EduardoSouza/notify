<?php

declare(strict_types=1);

namespace App\Messages;

enum OutboxStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
}
