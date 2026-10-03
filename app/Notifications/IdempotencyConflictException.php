<?php

declare(strict_types=1);

namespace App\Notifications;

use RuntimeException;

final class IdempotencyConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used with a different payload.');
    }
}
