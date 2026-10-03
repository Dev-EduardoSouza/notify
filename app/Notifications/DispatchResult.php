<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Delivery;

final class DispatchResult
{
    public function __construct(
        public readonly Delivery $delivery,
        public readonly bool $replayed,
    ) {
    }
}
