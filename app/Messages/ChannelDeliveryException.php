<?php

declare(strict_types=1);

namespace App\Messages;

use App\Notifications\Channels\SendResult;
use RuntimeException;

final class ChannelDeliveryException extends RuntimeException
{
    public function __construct(
        public readonly string $channel,
        public readonly string $reason,
        public readonly int $deliveredParts,
        public readonly int $totalParts,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(sprintf('%s (%d of %d parts delivered)', $reason, $deliveredParts, $totalParts));
    }

    public static function fromResult(string $channel, SendResult $result): self
    {
        $reason = $result->error ?? sprintf('%s: delivery failed', $channel);
        if ($result->retryAfterSeconds !== null) {
            $reason .= sprintf(', retry after %d s', $result->retryAfterSeconds);
        }

        return new self($channel, $reason, $result->deliveredParts, $result->totalParts, $result->retryAfterSeconds);
    }
}
