<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Delivery;
use App\Notifications\Channels\UnknownRecipientException;
use App\Notifications\DeliveryStatus;
use App\Notifications\IdempotencyConflictException;
use App\Notifications\NotificationRequest;
use App\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'notifications.channels.telegram.api_base' => 'https://tg.test',
            'notifications.channels.telegram.bot_token' => '1:TOKEN',
            'notifications.channels.telegram.recipients' => 'eduardo:42',
        ]);
        Carbon::setTestNow('2026-10-02 12:00:00');
    }

    public function testSendsAndForgetsTheText(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);

        $result = $this->service()->dispatch($this->request('oi'));

        self::assertFalse($result->replayed);
        $delivery = Delivery::findOrFail($result->delivery->id);
        self::assertSame(DeliveryStatus::Sent, $delivery->status);
        self::assertNull($delivery->text);
        self::assertSame(1, $delivery->attempts);
        self::assertSame(1, $delivery->delivered_parts);
        self::assertNotNull($delivery->sent_at);
    }

    public function testSameKeyAndPayloadReplaysWithoutSending(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);

        $first = $this->service()->dispatch($this->request('oi', 'k1'));
        $second = $this->service()->dispatch($this->request('oi', 'k1'));

        self::assertTrue($second->replayed);
        self::assertSame($first->delivery->id, $second->delivery->id);
        Http::assertSentCount(1);
        self::assertSame(1, Delivery::count());
    }

    public function testSameKeyWithDifferentPayloadIsAConflict(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->service()->dispatch($this->request('oi', 'k1'));

        $this->expectException(IdempotencyConflictException::class);
        $this->service()->dispatch($this->request('outro texto', 'k1'));
    }

    public function testKeysAreScopedPerClient(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->service()->dispatch($this->request('oi', 'k1', 'key:1'));
        $other = $this->service()->dispatch($this->request('oi', 'k1', 'key:2'));

        self::assertFalse($other->replayed);
        self::assertSame(2, Delivery::count());
    }

    public function testConcurrentInsertWithSameKeyReturnsTheWinner(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);
        $request = $this->request('oi', 'race');

        Delivery::creating(function () use ($request): void {
            Delivery::flushEventListeners();
            DB::table('deliveries')->insert([
                'id' => 'WINNER', 'client_ref' => $request->clientRef, 'idempotency_key' => 'race',
                'request_hash' => $request->hash(), 'channel' => 'telegram', 'recipient' => 'eduardo',
                'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $result = $this->service()->dispatch($request);

        self::assertTrue($result->replayed);
        self::assertSame('WINNER', $result->delivery->id);
        self::assertSame(1, Delivery::count());
        Http::assertNothingSent();
    }

    public function testTemporaryFailureSchedulesRetryHonouringRetryAfter(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false, 'parameters' => ['retry_after' => 900]], 429)]);

        $delivery = $this->service()->dispatch($this->request('oi'))->delivery;

        self::assertSame(DeliveryStatus::Retrying, $delivery->status);
        self::assertSame('oi', $delivery->text);
        self::assertEquals(Carbon::now()->addSeconds(900), $delivery->next_attempt_at);
    }

    public function testPermanentFailureForgetsTheText(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400)]);

        $delivery = $this->service()->dispatch($this->request('oi'))->delivery;

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertNull($delivery->text);
        self::assertSame('telegram: HTTP 400 Bad Request', $delivery->last_error);
    }

    public function testUnknownRecipientStoresNothing(): void
    {
        Http::fake();

        try {
            $this->service()->dispatch($this->request('oi', null, 'key:1', 'maria'));
            self::fail('Expected UnknownRecipientException');
        } catch (UnknownRecipientException) {
            self::assertSame(0, Delivery::count());
        }
    }

    private function request(string $text, ?string $key = null, string $client = 'key:1', string $recipient = 'eduardo'): NotificationRequest
    {
        return new NotificationRequest($client, 'telegram', $recipient, $text, 'plain', $key);
    }

    private function service(): NotificationService
    {
        return $this->app->make(NotificationService::class);
    }
}
