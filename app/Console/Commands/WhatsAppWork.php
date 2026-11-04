<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messages\OutboxStatus;
use App\Messages\RetryBackoff;
use App\Messages\WhatsAppOutbox;
use App\Messages\WhatsAppPacing;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\OutgoingMessage;
use App\Notifications\Channels\RecipientResolver;
use App\Notifications\Channels\SendOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

final class WhatsAppWork extends Command
{
    protected $signature = 'whatsapp:work {--once : Exit when no message is due instead of waiting for more}';

    protected $description = 'Send queued WhatsApp messages one at a time with growing spacing';

    public function handle(ChannelRegistry $channels, RecipientResolver $recipients): int
    {
        $channel = $channels->get('whatsapp');
        $idle = (int) config('notifications.channels.whatsapp.idle_reset_minutes', 30);
        $maxAttempts = (int) config('notifications.channels.whatsapp.max_attempts', 5);

        WhatsAppOutbox::query()->where('status', OutboxStatus::Sending->value)->update(['status' => OutboxStatus::Queued]);

        while (true) {
            $entry = $this->claim();
            if ($entry === null) {
                if ($this->option('once')) {
                    return self::SUCCESS;
                }
                Sleep::for(1)->second();
                continue;
            }

            $pacing = WhatsAppPacing::current();
            $number = $recipients->resolve('whatsapp', $entry->recipient);
            $result = $channel->send(
                new OutgoingMessage($number, (string) $entry->text),
                0,
                static function (int $deliveredParts): void {
                },
            );

            $now = CarbonImmutable::now();
            if ($result->outcome === SendOutcome::Sent) {
                $entry->update(['status' => OutboxStatus::Sent, 'sent_at' => $now, 'text' => null, 'last_error' => null]);
                $pacing->registerSent($now, $idle);
            } else {
                $attempts = $entry->attempts + 1;
                $error = $this->scrub((string) $result->error, [$number, (string) config('notifications.channels.whatsapp.api_key')]);
                if ($attempts >= $maxAttempts) {
                    $entry->update(['status' => OutboxStatus::Failed, 'attempts' => $attempts, 'last_error' => $error, 'text' => null]);
                } else {
                    $entry->update([
                        'status' => OutboxStatus::Queued,
                        'attempts' => $attempts,
                        'last_error' => $error,
                        'next_attempt_at' => $now->addSeconds(RetryBackoff::secondsFor($attempts)),
                    ]);
                }
            }

            Sleep::for($pacing->delaySeconds($now, $idle))->seconds();
        }
    }

    /** @param list<string> $secrets */
    private function scrub(string $error, array $secrets): string
    {
        foreach (array_filter($secrets) as $secret) {
            $error = str_replace($secret, '***', $error);
        }

        return mb_substr($error, 0, 255);
    }

    private function claim(): ?WhatsAppOutbox
    {
        return DB::transaction(function (): ?WhatsAppOutbox {
            $entry = WhatsAppOutbox::query()
                ->where('status', OutboxStatus::Queued->value)
                ->where('next_attempt_at', '<=', CarbonImmutable::now())
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            $entry?->update(['status' => OutboxStatus::Sending]);

            return $entry;
        });
    }
}
