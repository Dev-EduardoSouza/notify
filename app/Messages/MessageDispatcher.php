<?php

declare(strict_types=1);

namespace App\Messages;

use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\OutgoingMessage;
use App\Notifications\Channels\RecipientResolver;
use App\Notifications\Channels\SendOutcome;

final class MessageDispatcher
{
    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly RecipientResolver $recipients,
    ) {
    }

    public function send(string $channel, string $to, string $text): int
    {
        $deliveryChannel = $this->channels->get($channel);
        $target = $this->recipients->resolve($channel, $to);

        $result = $deliveryChannel->send(
            new OutgoingMessage($target, $text),
            0,
            static function (int $delivered): void {
            },
        );

        if ($result->outcome !== SendOutcome::Sent) {
            throw ChannelDeliveryException::fromResult($channel, $result);
        }

        return $result->totalParts;
    }
}
