<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Keeps secrets and message bodies out of the logs: sensitive context keys
 * are dropped, and token-shaped strings are masked wherever they appear
 * (the Telegram API URL carries the bot token, so exception messages can too).
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    public const REDACTED = '[redacted]';

    private const SENSITIVE_KEY = '/(token|secret|password|authorization|api_?key|^text$|^body$|chat_?id|recipients)/i';

    /** @var array<string, string> */
    private const PATTERNS = [
        '#/bot[0-9]+:[A-Za-z0-9_-]+#' => '/bot***',
        '#\b[0-9]{6,}:[A-Za-z0-9_-]{20,}\b#' => '***',
        '#Bearer\s+[A-Za-z0-9._~+/=-]+#i' => 'Bearer ***',
        '#\bntf_[A-Za-z0-9]+#' => 'ntf_***',
        '#\b(secret_|ntn_)[A-Za-z0-9]+#' => '$1***',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::scrub($record->message),
            context: $this->clean($record->context),
            extra: $this->clean($record->extra),
        );
    }

    public static function scrub(string $value): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $data[$key] = self::REDACTED;
                continue;
            }
            $data[$key] = $this->cleanValue($value);
        }

        return $data;
    }

    private function cleanValue(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => self::scrub($value),
            is_array($value) => $this->clean($value),
            $value instanceof Throwable => $this->describe($value),
            default => $value,
        };
    }

    /**
     * Replaces the exception object so the formatter never serializes
     * its raw message or trace arguments.
     *
     * @return array<string, mixed>
     */
    private function describe(Throwable $e): array
    {
        $described = [
            'class' => $e::class,
            'message' => self::scrub($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ];

        if ($e->getPrevious() !== null) {
            $described['previous'] = $this->describe($e->getPrevious());
        }

        return $described;
    }
}
