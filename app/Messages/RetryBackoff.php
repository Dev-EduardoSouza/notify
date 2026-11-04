<?php

declare(strict_types=1);

namespace App\Messages;

final class RetryBackoff
{
    public const BASE_SECONDS = 3;
    public const MAX_SECONDS = 90;

    public static function secondsFor(int $attempt): int
    {
        return min(self::MAX_SECONDS, self::BASE_SECONDS * (2 ** max(0, $attempt - 1)));
    }
}
