<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\RedactSecretsProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RedactSecretsProcessorTest extends TestCase
{
    private const URL = 'https://api.telegram.org/bot123456789:AAHfake_token_value_abcdefghij/sendMessage';

    public function testDropsSensitiveKeysAndMasksTokens(): void
    {
        $record = $this->process('Calling ' . self::URL, [
            'text' => 'private body',
            'api_key' => 'ntf_secret',
            'chat_id' => 123,
            'nested' => ['authorization' => 'Bearer abc', 'note' => 'used ntf_XYZ'],
            'delivery_id' => 7,
        ]);

        self::assertSame('Calling https://api.telegram.org/bot***/sendMessage', $record->message);
        self::assertSame(RedactSecretsProcessor::REDACTED, $record->context['text']);
        self::assertSame(RedactSecretsProcessor::REDACTED, $record->context['api_key']);
        self::assertSame(RedactSecretsProcessor::REDACTED, $record->context['chat_id']);
        self::assertSame(['authorization' => '[redacted]', 'note' => 'used ntf_***'], $record->context['nested']);
        self::assertSame(7, $record->context['delivery_id']);
    }

    public function testReplacesExceptionsWithScrubbedSummary(): void
    {
        $record = $this->process('failed', [
            'exception' => new RuntimeException('cURL error for ' . self::URL, 0, new RuntimeException('Bearer abc')),
        ]);

        $exception = $record->context['exception'];
        self::assertIsArray($exception);
        self::assertSame('cURL error for https://api.telegram.org/bot***/sendMessage', $exception['message']);
        self::assertSame('Bearer ***', $exception['previous']['message']);
        self::assertStringNotContainsString('AAHfake', (string) json_encode($record->context));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function process(string $message, array $context): LogRecord
    {
        $record = new LogRecord(new DateTimeImmutable(), 'test', Level::Info, $message, $context);

        return (new RedactSecretsProcessor())($record);
    }
}
