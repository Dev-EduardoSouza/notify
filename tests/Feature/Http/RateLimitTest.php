<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Auth\ClientKeyRegistry;
use Tests\TestCase;

final class RateLimitTest extends TestCase
{
    public function testFourthRequestInAMinuteIsLimitedPerKey(): void
    {
        $first = ClientKeyRegistry::generatePlainKey();
        $second = ClientKeyRegistry::generatePlainKey();
        config([
            'notifications.rate_limit_per_minute' => 3,
            'notifications.api_keys' => sprintf('a|%s|telegram|eduardo;b|%s|telegram|eduardo', ClientKeyRegistry::hash($first), ClientKeyRegistry::hash($second)),
        ]);
        $this->app->forgetInstance(ClientKeyRegistry::class);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/v1/messages', [], ['Authorization' => 'Bearer ' . $first])->assertStatus(400);
        }

        $this->postJson('/v1/messages', [], ['Authorization' => 'Bearer ' . $first])
            ->assertStatus(429)
            ->assertExactJson(['code' => 'rate_limited', 'message' => 'Too many requests.'])
            ->assertHeader('Retry-After');
        $this->postJson('/v1/messages', [], ['Authorization' => 'Bearer ' . $second])->assertStatus(400);
    }

}
