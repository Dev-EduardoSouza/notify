<?php

declare(strict_types=1);

namespace App\Integrations\Evolution;

final class EvolutionResponse
{
    public function __construct(
        public readonly int $status,
        public readonly bool $ok,
        public readonly string $description,
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
