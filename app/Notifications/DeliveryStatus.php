<?php

declare(strict_types=1);

namespace App\Notifications;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Retrying = 'retrying';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Sent || $this === self::Failed;
    }
}
