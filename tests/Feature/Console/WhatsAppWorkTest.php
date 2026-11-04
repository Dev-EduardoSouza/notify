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
