<?php

declare(strict_types=1);

namespace App\Integrations;

use RuntimeException;
use Throwable;

final class ExternalServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $service,
        string $reason,
        public readonly ?int $status = null,
    ) {
        parent::__construct(sprintf('%s: %s', $service, $reason));
    }

    public static function connection(string $service, Throwable $previous): self
    {
        $reason = preg_match('/cURL error \d+/', $previous->getMessage(), $match) === 1
            ? $match[0]
            : 'connection failed';

        return new self($service, $reason);
    }

    public static function http(string $service, int $status, string $detail = ''): self
    {
        $reason = sprintf('HTTP %d', $status);
        if ($detail !== '') {
            $reason .= ' ' . mb_substr($detail, 0, 200);
        }

        return new self($service, $reason, $status);
    }
}
