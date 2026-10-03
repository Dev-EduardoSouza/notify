<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use InvalidArgumentException;

final class UnknownRecipientException extends InvalidArgumentException
{
    public function __construct(public readonly string $channel, public readonly string $alias)
    {
        parent::__construct(sprintf('Recipient "%s" is not configured for channel "%s".', $alias, $channel));
    }
}
