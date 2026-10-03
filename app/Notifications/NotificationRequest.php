<?php

declare(strict_types=1);

namespace App\Notifications;

final class NotificationRequest
{
    public const SYSTEM_CLIENT = 'system';

    public function __construct(
        public readonly string $clientRef,
        public readonly string $channel,
        public readonly string $recipient,
        public readonly string $text,
        public readonly string $format = 'plain',
        public readonly ?string $idempotencyKey = null,
        public readonly ?int $apiKeyId = null,
    ) {
    }

    public static function forApiKey(int $apiKeyId, string $channel, string $recipient, string $text, string $format, ?string $idempotencyKey): self
    {
        return new self('key:' . $apiKeyId, $channel, $recipient, $text, $format, $idempotencyKey, $apiKeyId);
    }

    public static function forSystem(string $channel, string $recipient, string $text, string $idempotencyKey): self
    {
        return new self(self::SYSTEM_CLIENT, $channel, $recipient, $text, 'plain', $idempotencyKey);
    }

    /**
     * Fingerprint of what is being sent, to tell a retry from a conflicting reuse of a key.
     */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode([$this->channel, $this->recipient, $this->format, $this->text]));
    }
}
