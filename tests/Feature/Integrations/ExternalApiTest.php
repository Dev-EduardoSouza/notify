<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\ExternalServiceException;
use App\Integrations\Notion\NotionApi;
use App\Integrations\Telegram\TelegramApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ExternalApiTest extends TestCase
{
    private const TOKEN = '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'notifications.channels.telegram.api_base' => 'https://tg.test',
            'notifications.channels.telegram.bot_token' => self::TOKEN,
            'notifications.notion.api_base' => 'https://notion.test',
            'notifications.notion.token' => 'secret_notion',
            'notifications.notion.version' => '2022-06-28',
        ]);
    }

    public function testTelegramCallReturnsResult(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);

        $response = $this->app->make(TelegramApi::class)->call('sendMessage', ['chat_id' => 1, 'text' => 'hi']);

        self::assertTrue($response->ok);
        self::assertSame(['message_id' => 9], $response->result);
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://tg.test/bot' . self::TOKEN . '/sendMessage'
            && $r['text'] === 'hi');
    }

    public function testTelegramRateLimitIsDataNotException(): void
    {
        Http::fake(['tg.test/*' => Http::response(
            ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 31]],
            429,
        )]);

        $response = $this->app->make(TelegramApi::class)->call('sendMessage', []);

        self::assertFalse($response->ok);
        self::assertTrue($response->isRateLimited());
        self::assertSame(31, $response->retryAfter);
    }

    public function testConnectionFailureHidesUrlAndToken(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 7: Failed to connect for https://tg.test/bot' . self::TOKEN . '/sendMessage',
        ));

        try {
            $this->app->make(TelegramApi::class)->call('sendMessage', []);
            self::fail('Expected ExternalServiceException');
        } catch (ExternalServiceException $e) {
            self::assertSame('telegram: cURL error 7', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testNotionSendsVersionAndToken(): void
    {
        Http::fake(['notion.test/*' => Http::response(['results' => [], 'has_more' => false])]);

        $body = $this->app->make(NotionApi::class)->get('blocks/abc/children', ['page_size' => 100]);

        self::assertSame(['results' => [], 'has_more' => false], $body);
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://notion.test/v1/blocks/abc/children?page_size=100'
            && $r->hasHeader('Notion-Version', '2022-06-28')
            && $r->hasHeader('Authorization', 'Bearer secret_notion'));
    }

    public function testNotionHttpErrorBecomesException(): void
    {
        Http::fake(['notion.test/*' => Http::response(['code' => 'unauthorized', 'message' => 'API token is invalid.'], 401)]);

        $this->expectExceptionObject(new ExternalServiceException('notion', 'HTTP 401 unauthorized API token is invalid.', 401));
        $this->app->make(NotionApi::class)->get('blocks/abc/children');
    }
}
