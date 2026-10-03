<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\NotificationChannel;
use App\Notifications\Channels\OutgoingMessage;
use App\Notifications\Channels\RecipientResolver;
use App\Notifications\Channels\SendResult;
use App\Notifications\Channels\UnknownChannelException;
use App\Notifications\Channels\UnknownRecipientException;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class RecipientsAndChannelsTest extends TestCase
{
    public function testResolvesAliasesPerChannel(): void
    {
        $resolver = $this->resolver(' eduardo:123456 , Outro:-1001 ');

        self::assertSame('123456', $resolver->resolve('telegram', 'eduardo'));
        self::assertSame('-1001', $resolver->resolve('telegram', 'outro'));
        self::assertSame(['eduardo', 'outro'], $resolver->aliases('telegram'));
    }

    public function testUnknownAliasDoesNotLeakTargets(): void
    {
        try {
            $this->resolver('eduardo:123456')->resolve('telegram', 'maria');
            self::fail('Expected UnknownRecipientException');
        } catch (UnknownRecipientException $e) {
            self::assertStringNotContainsString('123456', $e->getMessage());
        }
    }

    public function testMalformedEntryIsRejectedWithoutEchoingIt(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid recipient entry in telegram recipients config.');
        $this->resolver('eduardo123456')->resolve('telegram', 'eduardo');
    }

    public function testRegistryReturnsRegisteredChannelsOnly(): void
    {
        $channel = new class () implements NotificationChannel {
            public function name(): string
            {
                return 'fake';
            }

            public function formats(): array
            {
                return ['plain'];
            }

            public function send(OutgoingMessage $message, int $fromPart, callable $onPartDelivered): SendResult
            {
                return SendResult::sent(1);
            }
        };
        $registry = new ChannelRegistry([$channel]);

        self::assertSame($channel, $registry->get('fake'));
        self::assertSame(['fake'], $registry->names());
        $this->expectException(UnknownChannelException::class);
        $registry->get('whatsapp');
    }

    private function resolver(string $recipients): RecipientResolver
    {
        return new RecipientResolver(new Repository(['notifications' => ['channels' => ['telegram' => ['recipients' => $recipients]]]]));
    }
}
