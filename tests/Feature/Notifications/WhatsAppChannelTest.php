<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Integrations\Evolution\EvolutionApi;
use App\Notifications\Channels\OutgoingMessage;
use App\Notifications\Channels\SendOutcome;
use App\Notifications\Channels\SendResult;
use App\Notifications\Channels\WhatsApp\WhatsAppChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WhatsAppChannelTest extends TestCase
{
    private const NUMBER = '5575999990000';

    private const KEY = 'EVOLUTION-KEY-SECRET';

    /** @var list<int> */
    private array $progress = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'notifications.channels.whatsapp.api_base' => 'https://evo.test',
            'notifications.channels.whatsapp.api_key' => self::KEY,
            'notifications.channels.whatsapp.instance' => 'notify-teste',
        ]);
        $this->app->forgetInstance(EvolutionApi::class);
    }

    public function testSendsTextToTheInstanceWithTheApiKey(): void
    {
        Http::fake(['evo.test/*' => Http::response(['key' => ['id' => 'ABC'], 'status' => 'PENDING'], 201)]);

        $result = $this->send('olá, mundo');

        self::assertSame(SendOutcome::Sent, $result->outcome);
        self::assertSame(1, $result->totalParts);
        self::assertSame([1], $this->progress);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://evo.test/message/sendText/notify-teste'
            && $r->hasHeader('apikey', self::KEY)
            && $r['number'] === self::NUMBER
            && $r['text'] === 'olá, mundo');
    }

    public function testDoesNotSendAgainWhenThePartWasAlreadyDelivered(): void
    {
        Http::fake();

        $result = $this->send('oi', fromPart: 1);

        self::assertSame(SendOutcome::Sent, $result->outcome);
        self::assertSame([], $this->progress);
        Http::assertNothingSent();
    }

    public function testNumberNotOnWhatsAppIsADefinitiveFailureWithoutTheNumber(): void
    {
        Http::fake(['evo.test/*' => Http::response([
            'status' => 400,
            'error' => 'Bad Request',
            'response' => ['message' => [['jid' => self::NUMBER . '@s.whatsapp.net', 'exists' => false, 'number' => self::NUMBER]]],
        ], 400)]);

        $result = $this->send('oi');

        self::assertSame(SendOutcome::Failed, $result->outcome);
        self::assertSame('whatsapp: HTTP 400 recipient is not on WhatsApp', $result->error);
        self::assertSame([], $this->progress);
        self::assertStringNotContainsString(self::NUMBER, (string) $result->error);
    }

    public function testRejectedApiKeyIsADefinitiveFailureWithoutTheKey(): void
    {
        Http::fake(['evo.test/*' => Http::response(['status' => 401, 'error' => 'Unauthorized'], 401)]);

        $result = $this->send('oi');

        self::assertSame(SendOutcome::Failed, $result->outcome);
        self::assertSame('whatsapp: HTTP 401 Unauthorized', $result->error);
        self::assertStringNotContainsString(self::KEY, (string) $result->error);
    }

    public function testServerErrorAndRateLimitAskForARetry(): void
    {
        Http::fake(['evo.test/*' => Http::sequence()
            ->push(['status' => 500, 'error' => 'Internal Server Error'], 500)
            ->push(['status' => 429, 'error' => 'Too Many Requests'], 429)]);

        $first = $this->send('oi');
        $second = $this->send('oi');

        self::assertSame(SendOutcome::Retry, $first->outcome);
        self::assertSame('whatsapp: HTTP 500 Internal Server Error', $first->error);
        self::assertSame(SendOutcome::Retry, $second->outcome);
        self::assertSame('whatsapp: HTTP 429 Too Many Requests', $second->error);
        self::assertSame([], $this->progress);
        Http::assertSentCount(2);
    }

    public function testConnectionFailureAsksForARetryWithoutLeakingTheKey(): void
    {
        Http::fake(['evo.test/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to evo.test')]);

        $result = $this->send('oi');

        self::assertSame(SendOutcome::Retry, $result->outcome);
        self::assertSame('whatsapp: cURL error 7', $result->error);
        self::assertStringNotContainsString(self::KEY, (string) $result->error);
    }

    private function send(string $text, int $fromPart = 0): SendResult
    {
        return $this->app->make(WhatsAppChannel::class)->send(
            new OutgoingMessage(self::NUMBER, $text),
            $fromPart,
            function (int $delivered): void {
                $this->progress[] = $delivered;
            },
        );
    }
}
