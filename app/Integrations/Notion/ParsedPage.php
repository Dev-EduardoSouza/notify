<?php

declare(strict_types=1);

namespace App\Integrations\Notion;

final class ParsedPage
{
    /** @param array<string, int> $ignoredBlocks */
    public function __construct(
        public readonly string $text,
        public readonly array $ignoredBlocks,
    ) {
    }

    public function ignoredCount(): int
    {
        return array_sum($this->ignoredBlocks);
    }
}
