<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Auth\ApiKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PostNotificationTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'notifications.channels.telegram.api_base' => 'https://tg.test',
            'notifications.channels.telegram.recipients' => 'eduardo:1,time:2',
        ]);
        Sleep::fake();
        $this->key = $this->app->make(ApiKeyManager::class)->issue('App', ['eduardo'])->plainKey;
    }

    public function testSentReturns201WithDelivery(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->send(['recipient' => 'eduardo', 'channel' => 'telegram', 'text' => 'oi'])
            ->assertStatus(201)
            ->assertJsonPath('status', 'sent')
            ->assertJsonStructure(['id', 'status', 'attempts', 'created_at', 'updated_at', 'sent_at'])
            ->assertJsonMissingPath('text');
    }

    public function testRetryingReturns202(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false, 'parameters' => ['retry_after' => 60]], 429)]);

        $this->send(['recipient' => 'eduardo', 'channel' => 'telegram', 'text' => 'oi'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'retrying');
    }

    public function testFailedReturns502WithDeliveryId(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400)]);

        $this->send(['recipient' => 'eduardo', 'channel' => 'telegram', 'text' => 'oi'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'delivery_failed')
            ->assertJsonPath('notification.status', 'failed')
            ->assertJsonStructure(['code', 'message', 'delivery_id']);
    }

    public function testRecipientOutsideKeyIsForbidden(): void
    {
        Http::fake();

        $this->send(['recipient' => 'time', 'channel' => 'telegram', 'text' => 'oi'])->assertStatus(403)->assertJsonPath('code', 'forbidden');
        Http::assertNothingSent();
    }

    public function testValidationErrors(): void
    {
        Http::fake();

        $this->send(['recipient' => 'eduardo', 'channel' => 'telegram', 'text' => str_repeat('é', 20001)])
            ->assertStatus(422)->assertJsonValidationErrors(['text'], 'errors');
        $this->send(['recipient' => 'eduardo', 'channel' => 'sms', 'text' => 'oi', 'format' => 'md'])
            ->assertStatus(422)->assertJsonValidationErrors(['channel', 'format'], 'errors');
        $this->send(['recipient' => 'eduardo', 'channel' => 'telegram', 'text' => 'oi', 'chat_id' => 1])
            ->assertStatus(422)->assertJsonPath('errors.body.0', 'Unknown field(s): chat_id.');
        $this->send(['recipient' => 'eduardo', 'channel' => 'telegram'])
            ->assertStatus(422)->assertJsonValidationErrors(['text'], 'errors');
        Http::assertNothingSent();
    }

    public function testInvalidJsonIs400(): void
    {
        $this->call('POST', '/v1/notifications', [], [], [], $this->server(), '{"recipient":')
            ->assertStatus(400)->assertJsonPath('code', 'bad_request');
        $this->call('POST', '/v1/notifications', [], [], [], $this->server(), '[1,2]')
            ->assertStatus(400);
    }

    public function testIdempotencyKeyReplaysAndDetectsConflicts(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);
        $body = ['recipient' => 'eduardo', 'channel' => 'telegram', 'text' => 'oi'];

        $first = $this->send($body, 'k-1')->assertStatus(201);
        $this->send($body, 'k-1')->assertStatus(201)->assertJsonPath('id', $first->json('id'))->assertHeader('Idempotent-Replayed', 'true');
        $this->send(['text' => 'outro'] + $body, 'k-1')->assertStatus(422)->assertJsonPath('code', 'idempotency_conflict');
        Http::assertSentCount(1);
    }

    /**
     * @param array<string, mixed> $body
     * @return TestResponse<Response>
     */
    private function send(array $body, ?string $idempotencyKey = null): TestResponse
    {
        return $this->call('POST', '/v1/notifications', [], [], [], $this->server($idempotencyKey), (string) json_encode($body));
    }

    /** @return array<string, string> */
    private function server(?string $idempotencyKey = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->key];
        if ($idempotencyKey !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        return $server;
    }
}
