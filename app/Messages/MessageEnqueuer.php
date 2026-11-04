<?php

declare(strict_types=1);

namespace App\Messages;

use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\RecipientResolver;

final class MessageEnqueuer
{
    public const QUEUED_CHANNELS = ['whatsapp'];

    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly RecipientResolver $recipients,
    ) {
    }

    public function handles(string $channel): bool
    {
        return in_array($channel, self::QUEUED_CHANNELS, true);
    }

    public function enqueue(string $apiKeyName, string $channel, string $to, string $text): int
    {
        $this->channels->get($channel);
        $this->recipients->resolve($channel, $to);

        $entry = WhatsAppOutbox::create([
            'api_key_name' => $apiKeyName,
            'recipient' => $to,
            'text' => $text,
            'status' => OutboxStatus::Queued,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ]);

        return $entry->id;
    }
}
