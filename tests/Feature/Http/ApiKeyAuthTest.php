<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Auth\ApiKeyManager;
use App\Auth\IssuedApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiKeyAuthTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/v1/notifications/01abc';

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.rate_limit_per_minute' => 3]);
    }

    public function testMissingWrongAndRevokedKeysGet401(): void
    {
        $revoked = $this->issue();
        $this->app->make(ApiKeyManager::class)->revoke($revoked->model->id);

        foreach ([[], ['Authorization' => 'Bearer ntf_wrong'], ['Authorization' => 'Bearer ' . $revoked->plainKey], ['Authorization' => $revoked->plainKey]] as $headers) {
            $this->getJson(self::URL, $headers)
                ->assertStatus(401)
                ->assertExactJson(['code' => 'unauthorized', 'message' => 'Missing or invalid API key.'])
                ->assertHeader('WWW-Authenticate', 'Bearer');
        }
    }

    public function testValidKeyPassesAuthentication(): void
    {
        $this->getJson(self::URL, $this->auth($this->issue()->plainKey))->assertStatus(404);
    }

    public function testFourthRequestInAMinuteIsRateLimitedPerKey(): void
    {
        $first = $this->auth($this->issue()->plainKey);
        $second = $this->auth($this->issue()->plainKey);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson(self::URL, $first)->assertStatus(404);
        }

        $this->getJson(self::URL, $first)
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited')
            ->assertHeader('Retry-After');
        $this->getJson(self::URL, $second)->assertStatus(404);
    }

    private function issue(): IssuedApiKey
    {
        return $this->app->make(ApiKeyManager::class)->issue('Test', ['eduardo']);
    }

    /** @return array<string, string> */
    private function auth(string $key): array
    {
        return ['Authorization' => 'Bearer ' . $key];
    }
}
