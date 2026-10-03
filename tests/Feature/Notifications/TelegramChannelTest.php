<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Integrations\Telegram\TelegramApi;
use App\Notifications\Channels\OutgoingMessage;
use App\Notifications\Channels\SendOutcome;
use App\Notifications\Channels\SendResult;
use App\Notifications\Channels\Telegram\TelegramChannel;
use App\Notifications\Text\TextSplitter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class TelegramChannelTest extends TestCase
{
    /** @var list<int> */
    private array $progress = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'notifications.channels.telegram.api_base' => 'https://tg.test',
            'notifications.channels.telegram.bot_token' => '1:TOKEN',
        ]);
        Sleep::fake();
    }

    public function testSendsEveryPartAndReportsProgress(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);

        $result = $this->send(str_repeat('a ', 25), fromPart: 0, limit: 20);

        self::assertSame(SendOutcome::Sent, $result->outcome);
        self::assertSame(3, $result->totalParts);
        self::assertSame([1, 2, 3], $this->progress);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r): bool => $r['chat_id'] === '42' && !isset($r['parse_mode']));
    }

    public function testHtmlFormatSetsParseMode(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->send('<b>oi</b>', format: 'html');

        Http::assertSent(fn (Request $r): bool => $r['parse_mode'] === 'HTML');
    }

    public function testResumesFromGivenPart(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);

        $result = $this->send(str_repeat('a ', 25), fromPart: 2, limit: 20);

        self::assertSame(SendOutcome::Sent, $result->outcome);
        self::assertSame([3], $this->progress);
        Http::assertSentCount(1);
    }

    public function testFailureOnThirdOfFourPartsKeepsDeliveredCount(): void
    {
        Http::fake(['tg.test/*' => Http::sequence()
            ->push(['ok' => true, 'result' => []])
            ->push(['ok' => true, 'result' => []])
            ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: x'], 400)]);

        $result = $this->send(str_repeat('a ', 35), limit: 20);

        self::assertSame(SendOutcome::Failed, $result->outcome);
        self::assertSame(2, $result->deliveredParts);
        self::assertSame(4, $result->totalParts);
        self::assertSame('telegram: HTTP 400 Bad Request: x', $result->error);
        self::assertSame([1, 2], $this->progress);
    }

    public function testShortRateLimitIsWaitedInline(): void
    {
        Http::fake(['tg.test/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 3]], 429)
            ->push(['ok' => true, 'result' => []])]);

        $result = $this->send('oi');

        self::assertSame(SendOutcome::Sent, $result->outcome);
        Sleep::assertSequence([Sleep::for(3)->seconds()]);
    }

    public function testLongRateLimitBecomesRetryWithDelay(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 40]], 429)]);

        $result = $this->send('oi');

        self::assertSame(SendOutcome::Retry, $result->outcome);
        self::assertSame(40, $result->retryAfterSeconds);
        Sleep::assertNeverSlept();
    }

    public function testServerErrorsAreRetriedInlineThenDeferred(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false, 'description' => 'Internal'], 502)]);

        $result = $this->send('oi');

        self::assertSame(SendOutcome::Retry, $result->outcome);
        Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(2)->seconds()]);
        Http::assertSentCount(3);
    }

    public function testNetworkErrorsAreRetriedThenDeferredWithoutToken(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout for https://tg.test/bot1:TOKEN/sendMessage'));

        $result = $this->send('oi');

        self::assertSame(SendOutcome::Retry, $result->outcome);
        self::assertSame('telegram: cURL error 28', $result->error);
    }

    private function send(string $text, int $fromPart = 0, int $limit = 3500, string $format = 'plain'): SendResult
    {
        $channel = new TelegramChannel(
            $this->app->make(TelegramApi::class),
            new TextSplitter($limit),
        );

        return $channel->send(new OutgoingMessage('42', $text, $format), $fromPart, function (int $delivered): void {
            $this->progress[] = $delivered;
        });
    }
}
