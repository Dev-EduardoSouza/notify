<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

final class ChannelRegistry
{
    /** @var array<string, NotificationChannel> */
    private array $channels = [];

    /**
     * @param iterable<NotificationChannel> $channels
     */
    public function __construct(iterable $channels = [])
    {
        foreach ($channels as $channel) {
            $this->register($channel);
        }
    }

    public function register(NotificationChannel $channel): void
    {
        $this->channels[$channel->name()] = $channel;
    }

    public function has(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    public function get(string $name): NotificationChannel
    {
        return $this->channels[$name] ?? throw new UnknownChannelException($name);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->channels);
    }
}
