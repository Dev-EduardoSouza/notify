<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
