<?php

declare(strict_types=1);

namespace App\Integrations\Telegram;

/**
 * Bot API answer, successful or not. HTTP errors are data here, not
 * exceptions, because the channel decides between retry and failure.
 */
final class TelegramResponse
{
    /**
     * @param array<string, mixed> $result
     */
    public function __construct(
        public readonly int $status,
        public readonly bool $ok,
        public readonly string $description = '',
        public readonly ?int $retryAfter = null,
        public readonly array $result = [],
    ) {
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500;
    }
}
