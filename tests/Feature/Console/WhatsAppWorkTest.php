<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Messages\OutboxStatus;
use App\Messages\WhatsAppOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class WhatsAppWorkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'notifications.channels.whatsapp.recipients' => 'eduardo:5571999990000',
            'notifications.channels.whatsapp.api_base' => 'http://evolution.test',
            'notifications.channels.whatsapp.api_key' => 'k',
            'notifications.channels.whatsapp.instance' => 'teste',
        ]);
        Sleep::fake();
    }

    public function testSendsQueuedMessagesInOrderWithSpacingBetweenThem(): void
    {
        Http::fake(['evolution.test/*' => Http::response(['key' => ['id' => 'x']], 201)]);
        foreach (['um', 'dois', 'tres'] as $text) {
            $this->enqueue($text);
        }

        $this->artisan('whatsapp:work', ['--once' => true])->assertSuccessful();

        $sent = Http::recorded()->map(fn (array $pair): string => $pair[0]->data()['text'])->all();
        self::assertSame(['um', 'dois', 'tres'], $sent);
        Sleep::assertSleptTimes(3);
        Sleep::assertSequence([Sleep::for(3)->seconds(), Sleep::for(3)->seconds(), Sleep::for(3)->seconds()]);
    }

    public function testSentMessageKeepsNoTextAndGetsSentAt(): void
    {
        Http::fake(['evolution.test/*' => Http::response([], 201)]);
        $entry = $this->enqueue('segredo');

        $this->artisan('whatsapp:work', ['--once' => true])->assertSuccessful();

        $entry->refresh();
        self::assertSame(OutboxStatus::Sent, $entry->status);
        self::assertNotNull($entry->sent_at);
        self::assertNull($entry->text);
    }

    public function testIgnoresMessagesNotYetDue(): void
    {
        Http::fake();
        $entry = $this->enqueue('depois');
        $entry->update(['next_attempt_at' => now()->addMinute()]);

        $this->artisan('whatsapp:work', ['--once' => true])->assertSuccessful();

        Http::assertNothingSent();
        self::assertSame(OutboxStatus::Queued, $entry->refresh()->status);
    }

    public function testFailureSchedulesBackoffAndKeepsTextForRetry(): void
    {
        Http::fake(['evolution.test/*' => Http::response([], 503)]);
        $entry = $this->enqueue('tente de novo');

        $this->artisan('whatsapp:work', ['--once' => true])->assertSuccessful();

        $entry->refresh();
        self::assertSame(OutboxStatus::Queued, $entry->status);
        self::assertSame(1, $entry->attempts);
        self::assertSame('tente de novo', $entry->text);
        self::assertNotNull($entry->last_error);
        self::assertEqualsWithDelta(3, now()->diffInSeconds($entry->next_attempt_at, false), 2);
    }

    public function testFifthFailureMarksFailedAndErasesText(): void
    {
        Http::fake(['evolution.test/*' => Http::response([], 503)]);
        $entry = $this->enqueue('ultima chance');
        $entry->update(['attempts' => 4]);

        $this->artisan('whatsapp:work', ['--once' => true])->assertSuccessful();

        $entry->refresh();
        self::assertSame(OutboxStatus::Failed, $entry->status);
        self::assertSame(5, $entry->attempts);
        self::assertNull($entry->text);
    }

    public function testLastErrorHoldsNeitherKeyNorNumber(): void
    {
        config(['notifications.channels.whatsapp.api_key' => 'segredo-da-evolution']);
        Http::fake(['evolution.test/*' => Http::response(['error' => 'falha 5571999990000 segredo-da-evolution'], 500)]);
        $entry = $this->enqueue('x');

        $this->artisan('whatsapp:work', ['--once' => true])->assertSuccessful();

        $error = (string) $entry->refresh()->last_error;
        self::assertNotSame('', $error);
        self::assertStringNotContainsString('5571999990000', $error);
        self::assertStringNotContainsString('segredo-da-evolution', $error);
    }

    public function testMessageStuckInSendingReturnsToQueueAndIsSentOnRestart(): void
    {
        Http::fake(['evolution.test/*' => Http::response([], 201)]);
        $entry = $this->enqueue('presa');
        $entry->update(['status' => OutboxStatus::Sending]);

        $this->artisan('whatsapp:work', ['--once' => true])->assertSuccessful();

        self::assertSame(OutboxStatus::Sent, $entry->refresh()->status);
        Http::assertSentCount(1);
    }

    private function enqueue(string $text): WhatsAppOutbox
    {
        return WhatsAppOutbox::create([
            'api_key_name' => 'local-whatsapp',
            'recipient' => 'eduardo',
            'text' => $text,
            'status' => OutboxStatus::Queued,
            'attempts' => 0,
            'next_attempt_at' => now()->subSecond(),
        ]);
    }
}
