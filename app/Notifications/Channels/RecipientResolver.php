<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Contracts\Config\Repository;
use UnexpectedValueException;

/**
 * Maps public aliases ("eduardo") to channel targets (chat ids) kept in
 * config, read from notifications.channels.<channel>.recipients as
 * "alias:target,alias2:target2".
 */
final class RecipientResolver
{
    public const ALIAS_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    public function __construct(private readonly Repository $config)
    {
    }

    public function resolve(string $channel, string $alias): string
    {
        return $this->map($channel)[$alias] ?? throw new UnknownRecipientException($channel, $alias);
    }

    /**
     * @return list<string>
     */
    public function aliases(string $channel): array
    {
        return array_keys($this->map($channel));
    }

    /**
     * @return array<string, string>
     */
    private function map(string $channel): array
    {
        $raw = $this->config->get("notifications.channels.{$channel}.recipients", '');
        if (!is_string($raw)) {
            return [];
        }

        $map = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
            [$alias, $target] = array_pad(explode(':', $pair, 2), 2, '');
            $alias = strtolower(trim($alias));
            $target = trim($target);
            if (preg_match(self::ALIAS_PATTERN, $alias) !== 1 || $target === '') {
                // Names the channel only: the pair may contain a chat id.
                throw new UnexpectedValueException(sprintf('Invalid recipient entry in %s recipients config.', $channel));
            }
            $map[$alias] = $target;
        }

        return $map;
    }
}
