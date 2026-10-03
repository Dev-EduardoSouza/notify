<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\ApiKeyManager;
use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

final class ApiKeyManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.channels.telegram.recipients' => 'eduardo:1,time:2']);
    }

    public function testIssuedKeyIsStoredOnlyAsHash(): void
    {
        $issued = $this->keys()->issue('App', [' Eduardo ', 'time', 'eduardo']);

        self::assertMatchesRegularExpression('/^ntf_[A-Za-z0-9_-]{43}$/', $issued->plainKey);
        $stored = ApiKey::findOrFail($issued->model->id);
        self::assertSame(hash('sha256', $issued->plainKey), $stored->key_hash);
        self::assertSame(substr($issued->plainKey, 0, 12), $stored->key_prefix);
        self::assertSame(['eduardo', 'time'], $stored->recipients);
        self::assertStringNotContainsString($issued->plainKey, (string) json_encode($stored->getAttributes()));
    }

    public function testAuthenticatesValidKeyAndRecordsUse(): void
    {
        Carbon::setTestNow('2026-10-02 12:00:00');
        $issued = $this->keys()->issue('App', ['eduardo']);

        $key = $this->keys()->authenticate($issued->plainKey);

        self::assertNotNull($key);
        self::assertSame($issued->model->id, $key->id);
        self::assertEquals(Carbon::now(), $key->fresh()?->last_used_at);
    }

    public function testRejectsWrongMalformedAndRevokedKeys(): void
    {
        $issued = $this->keys()->issue('App', ['eduardo']);

        self::assertNull($this->keys()->authenticate($issued->plainKey . 'x'));
        self::assertNull($this->keys()->authenticate('ntf_' . str_repeat('a', 43)));
        self::assertNull($this->keys()->authenticate('Bearer ' . $issued->plainKey));
        self::assertNull($this->keys()->authenticate(''));

        $this->keys()->revoke($issued->model->id);
        self::assertNull($this->keys()->authenticate($issued->plainKey));
    }

    public function testRejectsUnknownRecipientAlias(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown recipient alias: maria.');

        $this->keys()->issue('App', ['eduardo', 'maria']);
    }

    public function testCreateCommandShowsKeyOnceAndListNeverDoes(): void
    {
        $this->artisan('keys:create', ['name' => 'Cli', '--recipients' => 'eduardo'])
            ->expectsOutputToContain('key: ntf_')
            ->assertExitCode(0);

        $key = ApiKey::firstOrFail();
        $this->artisan('keys:list')
            ->expectsOutputToContain($key->key_prefix . '…')
            ->assertExitCode(0);
        $this->artisan('keys:revoke', ['id' => $key->id])->assertExitCode(0);
        self::assertTrue($key->fresh()?->isRevoked());
    }

    private function keys(): ApiKeyManager
    {
        return $this->app->make(ApiKeyManager::class);
    }
}
