<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Messages\ChannelDeliveryException;
use App\Messages\MessageDispatcher;
use App\Notifications\Channels\UnknownChannelException;
use App\Notifications\Channels\UnknownRecipientException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class MessageDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'notifications.channels.telegram.api_base' => 'https://tg.test',
            'notifications.channels.telegram.bot_token' => '123456:SECRETTOKENVALUEABCDEFGHIJ',
            'notifications.channels.telegram.recipients' => 'eduardo:42',
        ]);
        Sleep::fake();
    }

    public function testSendsAllPartsInOrder(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);
        $text = str_repeat('a', 3500) . str_repeat('b', 3500) . 'c';

        $parts = $this->dispatcher()->send('telegram', 'eduardo', $text);

        self::assertSame(3, $parts);
        $sent = Http::recorded()->map(fn (array $pair): string => $pair[0]['text'][0])->all();
        self::assertSame(['a', 'b', 'c'], $sent);
        Http::assertSent(fn (Request $r): bool => $r['chat_id'] === '42');
    }

    public function testShortRateLimitIsWaitedThenSent(): void
    {
        Http::fake(['tg.test/*' => Http::sequence()
            ->push(['ok' => false, 'parameters' => ['retry_after' => 2]], 429)
            ->push(['ok' => true, 'result' => []])]);

        self::assertSame(1, $this->dispatcher()->send('telegram', 'eduardo', 'oi'));
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    }

    public function testLongRateLimitFailsWithRetryHint(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false, 'parameters' => ['retry_after' => 60]], 429)]);

        $e = $this->failure();

        self::assertSame('telegram: rate limited, retry after 60 s', $e->reason);
        self::assertSame(60, $e->retryAfterSeconds);
    }

    public function testServerErrorsRetryTwiceThenFailWithoutToken(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false, 'description' => 'Internal Server Error'], 502)]);

        $e = $this->failure();

        Http::assertSentCount(3);
        self::assertSame('telegram: HTTP 502 Internal Server Error', $e->reason);
        self::assertStringNotContainsString('SECRETTOKEN', $e->getMessage());
    }

    public function testPartialFailureReportsDeliveredParts(): void
    {
        Http::fake(['tg.test/*' => Http::sequence()
            ->push(['ok' => true, 'result' => []])
            ->push(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);

        $e = $this->failure(str_repeat('a', 3500) . 'b');

        self::assertSame(1, $e->deliveredParts);
        self::assertSame(2, $e->totalParts);
    }

    public function testUnknownChannelIsRejectedBeforeAnyCall(): void
    {
        Http::fake();

        $this->expectException(UnknownChannelException::class);
        try {
            $this->dispatcher()->send('sms', 'eduardo', 'oi');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function testUnknownRecipientIsRejectedBeforeAnyCall(): void
    {
        Http::fake();

        $this->expectException(UnknownRecipientException::class);
        try {
            $this->dispatcher()->send('telegram', 'maria', 'oi');
        } finally {
            Http::assertNothingSent();
        }
    }

    private function failure(string $text = 'oi'): ChannelDeliveryException
    {
        try {
            $this->dispatcher()->send('telegram', 'eduardo', $text);
        } catch (ChannelDeliveryException $e) {
            return $e;
        }
        self::fail('Expected ChannelDeliveryException');
    }

    private function dispatcher(): MessageDispatcher
    {
        return $this->app->make(MessageDispatcher::class);
    }
}
