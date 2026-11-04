<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Auth\ClientKeyRegistry;
use App\Integrations\Evolution\EvolutionApi;
use App\Messages\OutboxStatus;
use App\Messages\WhatsAppOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WhatsAppEndpointTest extends TestCase
{
    use RefreshDatabase;

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

    public function testQueuesTheMessageInsteadOfSendingIt(): void
    {
        Http::fake();

        $response = $this->send(['channel' => 'whatsapp', 'to' => 'eduardo', 'text' => 'oi'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        $entry = WhatsAppOutbox::query()->sole();
        self::assertSame($entry->id, $response->json('id'));
        self::assertSame('app', $entry->api_key_name);
        self::assertSame('eduardo', $entry->recipient);
        self::assertSame('oi', $entry->text);
        self::assertSame(OutboxStatus::Queued, $entry->status);
        self::assertSame(0, $entry->attempts);
        self::assertNull($entry->last_error);
        self::assertNull($entry->sent_at);
        self::assertFalse($entry->next_attempt_at->isFuture());
        Http::assertNothingSent();
    }

    public function testEachRequestCreatesItsOwnEntry(): void
    {
        $first = $this->send(['channel' => 'whatsapp', 'to' => 'eduardo', 'text' => 'um'])->json('id');
        $second = $this->send(['channel' => 'whatsapp', 'to' => 'eduardo', 'text' => 'dois'])->json('id');

        self::assertNotSame($first, $second);
        self::assertSame(2, WhatsAppOutbox::query()->count());
    }

    public function testRecipientOutsideTheKeyIs403(): void
    {
        Http::fake();

        $this->send(['channel' => 'whatsapp', 'to' => 'maria', 'text' => 'oi'])
            ->assertStatus(403)->assertJsonPath('code', 'recipient_not_allowed');
        Http::assertNothingSent();
        self::assertSame(0, WhatsAppOutbox::query()->count());
    }

    public function testAllowedAliasWithoutANumberIs422(): void
    {
        Http::fake();

        $this->send(['channel' => 'whatsapp', 'to' => 'carla', 'text' => 'oi'])
            ->assertStatus(422)->assertJsonPath('code', 'unknown_recipient');
        Http::assertNothingSent();
        self::assertSame(0, WhatsAppOutbox::query()->count());
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
