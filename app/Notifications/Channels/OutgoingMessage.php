<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

final class OutgoingMessage
{
    /**
     * @param string $target Channel-specific address (Telegram chat id), never shown to clients.
     */
    public function __construct(
        public readonly string $target,
        public readonly string $text,
        public readonly string $format = 'plain',
    ) {
    }
}
