<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use InvalidArgumentException;

final class UnknownChannelException extends InvalidArgumentException
{
    public function __construct(public readonly string $channel)
    {
        parent::__construct(sprintf('Channel "%s" is not available.', $channel));
    }
}
