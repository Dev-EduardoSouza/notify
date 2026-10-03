<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

final class SendResult
{
    public function __construct(
        public readonly SendOutcome $outcome,
        public readonly int $deliveredParts,
        public readonly int $totalParts,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function sent(int $totalParts): self
    {
        return new self(SendOutcome::Sent, $totalParts, $totalParts);
    }

    public static function retry(int $delivered, int $total, string $error, ?int $retryAfterSeconds = null): self
    {
        return new self(SendOutcome::Retry, $delivered, $total, $retryAfterSeconds, $error);
    }

    public static function failed(int $delivered, int $total, string $error): self
    {
        return new self(SendOutcome::Failed, $delivered, $total, null, $error);
    }
}
