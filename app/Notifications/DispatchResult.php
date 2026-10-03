<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Delivery;

final class DispatchResult
{
    /**
     * @param bool $replayed True when an Idempotency-Key matched an earlier delivery and nothing was sent.
     */
    public function __construct(
        public readonly Delivery $delivery,
        public readonly bool $replayed,
    ) {
    }
}
