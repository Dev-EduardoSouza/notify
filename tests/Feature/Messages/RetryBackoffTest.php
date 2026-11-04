<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Messages\RetryBackoff;
use PHPUnit\Framework\TestCase;

final class RetryBackoffTest extends TestCase
{
    public function testDoublesPerAttemptUpToNinety(): void
    {
        $expected = [1 => 3, 2 => 6, 3 => 12, 4 => 24, 5 => 48, 6 => 90, 10 => 90];

        foreach ($expected as $attempt => $seconds) {
            self::assertSame($seconds, RetryBackoff::secondsFor($attempt), "tentativa {$attempt}");
        }
    }
}
