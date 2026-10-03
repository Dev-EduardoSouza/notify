<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

final class OutgoingMessage
{
    public function __construct(
        public readonly string $target,
        public readonly string $text,
        public readonly string $format = 'plain',
    ) {
    }
}
