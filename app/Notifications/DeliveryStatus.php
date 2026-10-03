<?php

declare(strict_types=1);

namespace App\Notifications;

enum DeliveryStatus: string
{
    // Created, first attempt in progress.
    case Pending = 'pending';
    case Sent = 'sent';
    // Waiting for notifications:retry.
    case Retrying = 'retrying';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Sent || $this === self::Failed;
    }
}
