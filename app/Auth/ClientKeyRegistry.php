<?php

declare(strict_types=1);

namespace App\Auth;

use UnexpectedValueException;

final class ClientKeyRegistry
{
    public const PREFIX = 'ntf_';

    public const NAME_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    /** @var list<ClientKey> */
    private readonly array $keys;

    public function __construct(string $definitions)
    {
        $this->keys = self::parse($definitions);
    }

    public function authenticate(string $plain): ?ClientKey
    {
        if (!str_starts_with($plain, self::PREFIX) || strlen($plain) > 128) {
            return null;
        }

        $hash = self::hash($plain);
        $match = null;
        foreach ($this->keys as $key) {
            if (hash_equals($key->hash, $hash)) {
                $match = $key;
            }
        }

        return $match;
    }

    public function has(string $name): bool
    {
        foreach ($this->keys as $key) {
            if ($key->name === $name) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ClientKey> */
    public function all(): array
    {
        return $this->keys;
    }

    public static function generatePlainKey(): string
    {
        return self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** @return list<ClientKey> */
    private static function parse(string $definitions): array
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(';', $definitions))) as $index => $entry) {
            $fields = array_map('trim', explode('|', $entry));
            if (count($fields) !== 4 || preg_match(self::NAME_PATTERN, $fields[0]) !== 1 || preg_match('/^[a-f0-9]{64}$/', $fields[1]) !== 1) {
                throw new UnexpectedValueException(sprintf('API_KEYS entry %d is malformed.', $index + 1));
            }
            $keys[] = new ClientKey($fields[0], $fields[1], self::list($fields[2]), self::list($fields[3]));
        }

        return $keys;
    }

    /** @return list<string> */
    private static function list(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map(fn (string $item): string => strtolower(trim($item)), explode(',', $csv)))));
    }
}
