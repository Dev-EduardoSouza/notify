<?php

declare(strict_types=1);

namespace App\Notifications;

use InvalidArgumentException;

final class UnsupportedFormatException extends InvalidArgumentException
{
    public function __construct(string $channel, string $format)
    {
        parent::__construct(sprintf('Channel "%s" does not support format "%s".', $channel, $format));
    }
}
