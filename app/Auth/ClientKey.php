<?php

declare(strict_types=1);

namespace App\Auth;

final class ClientKey
{
    /**
     * @param list<string> $channels
     * @param list<string> $recipients
     */
    public function __construct(
        public readonly string $name,
        public readonly string $hash,
        public readonly array $channels,
        public readonly array $recipients,
    ) {
    }

    public function allowsChannel(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    public function allowsRecipient(string $alias): bool
    {
        return in_array($alias, $this->recipients, true);
    }

    public function toEnvEntry(): string
    {
        return implode('|', [$this->name, $this->hash, implode(',', $this->channels), implode(',', $this->recipients)]);
    }
}
