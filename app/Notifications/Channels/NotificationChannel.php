<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

interface NotificationChannel
{
    public function name(): string;

    /** @return list<string> */
    public function formats(): array;

    /** @param callable(int $deliveredParts): void $onPartDelivered */
    public function send(OutgoingMessage $message, int $fromPart, callable $onPartDelivered): SendResult;
}
