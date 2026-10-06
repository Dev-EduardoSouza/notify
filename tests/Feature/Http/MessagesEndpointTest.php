<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Auth\ClientKeyRegistry;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MessagesEndpointTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = ClientKeyRegistry::generatePlainKey();
        config([
            'notifications.api_keys' => 'app|' . ClientKeyRegistry::hash($this->key) . '|telegram|eduardo',
            'notifications.channels.telegram.api_base' => 'https://tg.test',
            'notifications.channels.telegram.bot_token' => '123456:SECRETTOKENVALUEABCDEFGHIJ',
            'notifications.channels.telegram.recipients' => 'eduardo:42,maria:43',
        ]);
        $this->app->forgetInstance(ClientKeyRegistry::class);
        Sleep::fake();
    }

    public function testSuccessReturnsStatusAndParts(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->send(['channel' => 'telegram', 'to' => 'eduardo', 'text' => str_repeat('a', 3501)])
            ->assertOk()
            ->assertExactJson(['status' => 'sent', 'parts' => 2]);
    }

    public function testInvalidKeyIs401(): void
    {
        $this->send(['channel' => 'telegram', 'to' => 'eduardo', 'text' => 'oi'], 'ntf_wrong')
            ->assertStatus(401)->assertJsonPath('code', 'unauthorized');
    }

    public function testChannelAndRecipientOutsideTheKeyAre403(): void
    {
        Http::fake();

        $this->send(['channel' => 'sms', 'to' => 'eduardo', 'text' => 'oi'])->assertStatus(403)->assertJsonPath('code', 'channel_not_allowed');
        $this->send(['channel' => 'telegram', 'to' => 'maria', 'text' => 'oi'])->assertStatus(403)->assertJsonPath('code', 'recipient_not_allowed');
        Http::assertNothingSent();
    }

    public function testBodyErrors(): void
    {
        Http::fake();

        $this->call('POST', '/v1/messages', [], [], [], $this->server(), '{"channel":')->assertStatus(400)->assertJsonPath('code', 'bad_request');
        $this->send(['channel' => 'telegram', 'to' => 'eduardo', 'text' => 'oi', 'extra' => 1])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->send(['channel' => 'telegram', 'to' => 'eduardo', 'text' => str_repeat('é', 20001)])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function testChannelFailureIs502WithoutToken(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $response = $this->send(['channel' => 'telegram', 'to' => 'eduardo', 'text' => 'oi'])
            ->assertStatus(502)
            ->assertExactJson(['code' => 'channel_failed', 'message' => 'telegram: HTTP 401 Unauthorized (0 of 1 parts delivered)']);

        self::assertStringNotContainsString('SECRETTOKEN', (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $body
     * @return TestResponse<Response>
     */
    private function send(array $body, ?string $key = null): TestResponse
    {
        return $this->call('POST', '/v1/messages', [], [], [], $this->server($key), (string) json_encode($body));
    }

    /** @return array<string, string> */
    private function server(?string $key = null): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . ($key ?? $this->key)];
    }
}
