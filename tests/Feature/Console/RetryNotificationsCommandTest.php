<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Delivery;
use App\Notifications\DeliveryStatus;
use App\Notifications\Text\TextSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class RetryNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'notifications.channels.telegram.api_base' => 'https://tg.test',
            'notifications.channels.telegram.bot_token' => '1:TOKEN',
            'notifications.channels.telegram.recipients' => 'eduardo:42',
            'notifications.delivery_retention_days' => 30,
        ]);
        Carbon::setTestNow('2026-10-02 12:00:00');
        Sleep::fake();
    }

    public function testDueDeliveryResumesFromNextPart(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);
        $delivery = $this->retrying(text: "parte um\n\nparte dois", deliveredParts: 1, partsTotal: 2, limit: 12);

        $this->artisan('notifications:retry')->expectsOutput('processed=1 sent=1 retrying=0 failed=0 purged=0')->assertExitCode(0);

        $delivery->refresh();
        self::assertSame(DeliveryStatus::Sent, $delivery->status);
        self::assertNull($delivery->text);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r['text'] === 'parte dois');
    }

    public function testNotDueDeliveryIsLeftAlone(): void
    {
        Http::fake();
        $this->retrying(nextAttemptAt: Carbon::now()->addMinute());

        $this->artisan('notifications:retry')->expectsOutput('processed=0 sent=0 retrying=0 failed=0 purged=0');

        Http::assertNothingSent();
    }

    public function testBackoffGrowsAndFifthAttemptFails(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false], 500)]);
        $delivery = $this->retrying(attempts: 1);

        foreach ([15, 30, 60] as $expectedMinutes) {
            $this->artisan('notifications:retry');
            $delivery->refresh();
            self::assertSame(DeliveryStatus::Retrying, $delivery->status);
            self::assertEquals(Carbon::now()->addMinutes($expectedMinutes), $delivery->next_attempt_at);
            $delivery->update(['next_attempt_at' => Carbon::now()->subSecond()]);
        }

        $this->artisan('notifications:retry')->expectsOutput('processed=1 sent=0 retrying=0 failed=1 purged=0');
        $delivery->refresh();
        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertSame(5, $delivery->attempts);
        self::assertNull($delivery->text);
    }

    public function testStalePendingDeliveryIsPickedUp(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true, 'result' => []])]);
        $delivery = $this->retrying(status: DeliveryStatus::Pending, nextAttemptAt: null);
        Delivery::query()->whereKey($delivery->id)->update(['updated_at' => Carbon::now()->subMinutes(11)]);

        $this->artisan('notifications:retry')->expectsOutput('processed=1 sent=1 retrying=0 failed=0 purged=0');
    }

    public function testPurgesOnlyOldFinishedDeliveries(): void
    {
        Http::fake();
        $old = $this->retrying(status: DeliveryStatus::Sent, nextAttemptAt: null);
        $recent = $this->retrying(status: DeliveryStatus::Failed, nextAttemptAt: null);
        $waiting = $this->retrying(nextAttemptAt: Carbon::now()->addHour());
        Delivery::query()->whereKey([$old->id, $waiting->id])->update(['updated_at' => Carbon::now()->subDays(31)]);

        $this->artisan('notifications:retry')->expectsOutput('processed=0 sent=0 retrying=0 failed=0 purged=1');

        self::assertNull(Delivery::find($old->id));
        self::assertNotNull(Delivery::find($recent->id));
        self::assertNotNull(Delivery::find($waiting->id));
    }

    public function testIsScheduledEveryFiveMinutes(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('*/5 * * * *  php artisan notifications:retry');
    }

    private function retrying(
        string $text = 'oi',
        int $attempts = 1,
        int $deliveredParts = 0,
        int $partsTotal = 1,
        ?Carbon $nextAttemptAt = new Carbon('2026-10-02 11:59:00'),
        DeliveryStatus $status = DeliveryStatus::Retrying,
        int $limit = 3500,
    ): Delivery {
        $this->app->bind(TextSplitter::class, fn (): TextSplitter => new TextSplitter($limit));

        return Delivery::create([
            'client_ref' => 'system',
            'request_hash' => str_repeat('0', 64),
            'channel' => 'telegram',
            'recipient' => 'eduardo',
            'text' => $status->isFinal() ? null : $text,
            'status' => $status,
            'attempts' => $attempts,
            'delivered_parts' => $deliveredParts,
            'parts_total' => $partsTotal,
            'next_attempt_at' => $nextAttemptAt,
        ]);
    }
}
