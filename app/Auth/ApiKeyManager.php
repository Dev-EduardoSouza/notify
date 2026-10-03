<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\ApiKey;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\RecipientResolver;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class ApiKeyManager
{
    public const PREFIX = 'ntf_';

    public const VISIBLE_PREFIX_LENGTH = 12;

    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly RecipientResolver $recipients,
    ) {
    }

    /** @param list<string> $recipients */
    public function issue(string $name, array $recipients): IssuedApiKey
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('The key name must have between 1 and 100 characters.');
        }

        $recipients = array_values(array_unique(array_map(fn (string $alias): string => strtolower(trim($alias)), $recipients)));
        if ($recipients === [] || in_array('', $recipients, true)) {
            throw new InvalidArgumentException('At least one recipient alias is required.');
        }

        $known = $this->knownAliases();
        $unknown = array_values(array_diff($recipients, $known));
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Unknown recipient alias: %s.', implode(', ', $unknown)));
        }

        $plain = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $model = ApiKey::create([
            'name' => $name,
            'key_hash' => self::hash($plain),
            'key_prefix' => substr($plain, 0, self::VISIBLE_PREFIX_LENGTH),
            'recipients' => $recipients,
        ]);

        return new IssuedApiKey($model, $plain);
    }

    public function authenticate(string $plain): ?ApiKey
    {
        if (!str_starts_with($plain, self::PREFIX) || strlen($plain) > 128) {
            return null;
        }

        $hash = self::hash($plain);
        $key = ApiKey::query()->where('key_hash', $hash)->first();

        if ($key === null || !hash_equals($key->key_hash, $hash) || $key->isRevoked()) {
            return null;
        }

        $key->forceFill(['last_used_at' => Carbon::now()])->saveQuietly();

        return $key;
    }

    public function revoke(int $id): ?ApiKey
    {
        $key = ApiKey::find($id);
        if ($key === null) {
            return null;
        }

        if (!$key->isRevoked()) {
            $key->revoked_at = Carbon::now();
            $key->save();
        }

        return $key;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** @return list<string> */
    private function knownAliases(): array
    {
        $aliases = [];
        foreach ($this->channels->names() as $channel) {
            array_push($aliases, ...$this->recipients->aliases($channel));
        }

        return array_values(array_unique($aliases));
    }
}
