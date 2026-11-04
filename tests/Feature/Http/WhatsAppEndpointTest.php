<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Auth\ClientKeyRegistry;
use App\Integrations\Evolution\EvolutionApi;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WhatsAppEndpointTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = ClientKeyRegistry::generatePlainKey();
        config([
            'notifications.api_keys' => 'app|' . ClientKeyRegistry::hash($this->key) . '|whatsapp|eduardo,carla',
            'notifications.channels.whatsapp.api_base' => 'https://evo.test',
            'notifications.channels.whatsapp.api_key' => 'EVOLUTION-KEY-SECRET',
            'notifications.channels.whatsapp.instance' => 'notify-teste',
            'notifications.channels.whatsapp.recipients' => 'eduardo:5575999990000,maria:5575999990001',
        ]);
        $this->app->forgetInstance(ClientKeyRegistry::class);
        $this->app->forgetInstance(EvolutionApi::class);
    }

    public function testSendsThroughTheWhatsAppChannel(): void
    {
        Http::fake(['evo.test/*' => Http::response(['status' => 'PENDING'], 201)]);

        $this->send(['channel' => 'whatsapp', 'to' => 'eduardo', 'text' => 'oi'])
            ->assertOk()
            ->assertExactJson(['status' => 'sent', 'parts' => 1]);

        Http::assertSent(fn ($r): bool => $r['number'] === '5575999990000' && $r['text'] === 'oi');
    }

    public function testRecipientOutsideTheKeyIs403(): void
    {
        Http::fake();

        $this->send(['channel' => 'whatsapp', 'to' => 'maria', 'text' => 'oi'])
            ->assertStatus(403)->assertJsonPath('code', 'recipient_not_allowed');
        Http::assertNothingSent();
    }

    public function testAllowedAliasWithoutANumberIs422(): void
    {
        Http::fake();

        $this->send(['channel' => 'whatsapp', 'to' => 'carla', 'text' => 'oi'])
            ->assertStatus(422)->assertJsonPath('code', 'unknown_recipient');
        Http::assertNothingSent();
    }

    public function testChannelFailureIs502WithoutNumberOrKey(): void
    {
        Http::fake(['evo.test/*' => Http::response([
            'status' => 400,
            'error' => 'Bad Request',
            'response' => ['message' => [['exists' => false, 'number' => '5575999990000']]],
        ], 400)]);

        $response = $this->send(['channel' => 'whatsapp', 'to' => 'eduardo', 'text' => 'oi'])
            ->assertStatus(502)
            ->assertExactJson(['code' => 'channel_failed', 'message' => 'whatsapp: HTTP 400 recipient is not on WhatsApp (0 of 1 parts delivered)']);

        self::assertStringNotContainsString('5575999990000', (string) $response->getContent());
        self::assertStringNotContainsString('EVOLUTION-KEY-SECRET', (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $body
     * @return TestResponse<Response>
     */
    private function send(array $body): TestResponse
    {
        return $this->call('POST', '/v1/messages', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->key,
        ], (string) json_encode($body));
    }
}
