<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Auth\ClientKeyRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class MessageLogTest extends TestCase
{
    public function testLogsOneLinePerRequestWithoutTextOrSecrets(): void
    {
        $key = ClientKeyRegistry::generatePlainKey();
        config([
            'notifications.api_keys' => 'app|' . ClientKeyRegistry::hash($key) . '|telegram|eduardo',
            'notifications.channels.telegram.api_base' => 'https://tg.test',
            'notifications.channels.telegram.recipients' => 'eduardo:42',
        ]);
        $this->app->forgetInstance(ClientKeyRegistry::class);
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);
        $log = Log::spy();

        $this->postJson('/v1/messages', ['channel' => 'telegram', 'to' => 'eduardo', 'text' => 'conteúdo privado'], ['Authorization' => 'Bearer ' . $key])->assertOk();
        $this->postJson('/v1/messages', ['channel' => 'telegram', 'to' => 'eduardo', 'text' => 'oi'], ['Authorization' => 'Bearer ntf_wrong'])->assertStatus(401);

        $log->shouldHaveReceived('info')->with('message request', ['key' => 'app', 'channel' => 'telegram', 'to' => 'eduardo', 'status' => 200, 'parts' => 1])->once();
        $log->shouldHaveReceived('info')->with('message request', ['channel' => 'telegram', 'to' => 'eduardo', 'status' => 401, 'code' => 'unauthorized'])->once();
    }
}
