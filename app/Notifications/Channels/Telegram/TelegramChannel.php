<?php

declare(strict_types=1);

namespace App\Notifications\Channels\Telegram;

use App\Integrations\ExternalServiceException;
use App\Integrations\Telegram\TelegramApi;
use App\Integrations\Telegram\TelegramResponse;
use App\Notifications\Channels\NotificationChannel;
use App\Notifications\Channels\OutgoingMessage;
use App\Notifications\Channels\SendResult;
use App\Notifications\Text\TextSplitter;
use Closure;
use Illuminate\Support\Facades\Log;

final class TelegramChannel implements NotificationChannel
{
    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @param (Closure(int): void)|null $sleep */
    public function __construct(
        private readonly TelegramApi $api,
        private readonly TextSplitter $splitter,
        private readonly int $maxInlineWaitSeconds = 5,
        private readonly int $inlineRetriesPerPart = 2,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function name(): string
    {
        return 'telegram';
    }

    public function formats(): array
    {
        return ['plain', 'html'];
    }

    public function send(OutgoingMessage $message, int $fromPart, callable $onPartDelivered): SendResult
    {
        $parts = $this->splitter->split($message->text);
        $total = count($parts);

        for ($index = $fromPart; $index < $total; $index++) {
            if (trim($parts[$index]) !== '') {
                $failure = $this->sendPart($message, $parts[$index], $index, $total);
                if ($failure !== null) {
                    return $failure;
                }
            }
            $onPartDelivered($index + 1);
        }

        return SendResult::sent($total);
    }

    /** @return SendResult|null null when the part was delivered. */
    private function sendPart(OutgoingMessage $message, string $text, int $index, int $total): ?SendResult
    {
        $params = [
            'chat_id' => $message->target,
            'text' => $text,
            'link_preview_options' => ['is_disabled' => true],
        ];
        if ($message->format === 'html') {
            $params['parse_mode'] = 'HTML';
        }

        $transientFailures = 0;
        $waitedForRateLimit = false;

        while (true) {
            try {
                $response = $this->api->call('sendMessage', $params);
            } catch (ExternalServiceException $e) {
                if ($transientFailures++ < $this->inlineRetriesPerPart) {
                    ($this->sleep)($transientFailures);
                    continue;
                }

                return $this->give(SendResult::retry($index, $total, $e->getMessage()), $index);
            }

            if ($response->ok) {
                return null;
            }

            if ($response->isRateLimited()) {
                $wait = $response->retryAfter ?? 30;
                if (!$waitedForRateLimit && $wait <= $this->maxInlineWaitSeconds) {
                    $waitedForRateLimit = true;
                    ($this->sleep)($wait);
                    continue;
                }

                return $this->give(SendResult::retry($index, $total, 'telegram: rate limited', $wait), $index, $response);
            }

            if ($response->isServerError()) {
                if ($transientFailures++ < $this->inlineRetriesPerPart) {
                    ($this->sleep)($transientFailures);
                    continue;
                }

                return $this->give(SendResult::retry($index, $total, $this->describe($response)), $index, $response);
            }

            return $this->give(SendResult::failed($index, $total, $this->describe($response)), $index, $response);
        }
    }

    private function describe(TelegramResponse $response): string
    {
        return mb_substr(trim(sprintf('telegram: HTTP %d %s', $response->status, $response->description)), 0, 250);
    }

    private function give(SendResult $result, int $index, ?TelegramResponse $response = null): SendResult
    {
        Log::warning('Telegram part not delivered', [
            'outcome' => $result->outcome->value,
            'part' => $index + 1,
            'parts_total' => $result->totalParts,
            'status' => $response?->status,
            'retry_after' => $result->retryAfterSeconds,
            'error' => $result->error,
        ]);

        return $result;
    }
}
