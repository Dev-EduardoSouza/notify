<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\ClientKeyRegistry;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ClientKeyRegistryTest extends TestCase
{
    public function testAuthenticatesOnlyTheExactKey(): void
    {
        $plain = ClientKeyRegistry::generatePlainKey();
        $other = ClientKeyRegistry::generatePlainKey();
        $registry = new ClientKeyRegistry(sprintf(
            ' app-a|%s|telegram| Eduardo ,time ; app-b|%s|telegram|eduardo ',
            ClientKeyRegistry::hash($plain),
            ClientKeyRegistry::hash($other),
        ));

        $key = $registry->authenticate($plain);
        self::assertNotNull($key);
        self::assertSame('app-a', $key->name);
        self::assertSame(['telegram'], $key->channels);
        self::assertSame(['eduardo', 'time'], $key->recipients);
        self::assertSame('app-b', $registry->authenticate($other)?->name);

        self::assertNull($registry->authenticate(substr($plain, 0, -1) . 'x'));
        self::assertNull($registry->authenticate('Bearer ' . $plain));
        self::assertNull($registry->authenticate(''));
    }

    public function testGeneratedKeysAreLongAndUnique(): void
    {
        $first = ClientKeyRegistry::generatePlainKey();

        self::assertMatchesRegularExpression('/^ntf_[A-Za-z0-9_-]{43}$/', $first);
        self::assertNotSame($first, ClientKeyRegistry::generatePlainKey());
    }

    public function testEmptyDefinitionHasNoKeys(): void
    {
        self::assertSame([], (new ClientKeyRegistry(''))->all());
    }

    public function testMalformedEntryIsRejectedWithoutEchoingIt(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('API_KEYS entry 1 is malformed.');

        new ClientKeyRegistry('app|nao-e-hash|telegram|eduardo');
    }
}
