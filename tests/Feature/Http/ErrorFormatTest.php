<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class ErrorFormatTest extends TestCase
{
    public function testUnexpectedErrorHidesDetailsEvenInDebug(): void
    {
        config(['app.debug' => true]);
        Route::get('/boom', fn () => throw new RuntimeException('secret detail /bot123:ABC/'));

        $response = $this->getJson('/boom');

        $response->assertStatus(500)->assertExactJson(['code' => 'internal_error', 'message' => 'Internal server error.']);
        self::assertStringNotContainsString('secret detail', (string) $response->getContent());
    }

    public function testMethodNotAllowedIsJson(): void
    {
        $this->postJson('/health')->assertStatus(405)->assertExactJson(['code' => 'method_not_allowed', 'message' => 'Method not allowed.']);
    }

    public function testBodyOverLimitIsRejected(): void
    {
        config(['notifications.max_body_bytes' => 10]);

        $this->call('POST', '/health', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('a', 11))
            ->assertStatus(413)
            ->assertJsonPath('code', 'payload_too_large')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function testNoCorsHeaders(): void
    {
        $this->call('OPTIONS', '/health', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
