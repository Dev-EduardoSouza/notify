<?php

declare(strict_types=1);

namespace App\Notifications\Channels\WhatsApp;

use App\Integrations\Evolution\EvolutionApi;
use App\Integrations\Evolution\EvolutionResponse;
use App\Integrations\ExternalServiceException;
use App\Notifications\Channels\NotificationChannel;
use App\Notifications\Channels\OutgoingMessage;
use App\Notifications\Channels\SendResult;
use Illuminate\Support\Facades\Log;

final class WhatsAppChannel implements NotificationChannel
{
    public function __construct(private readonly EvolutionApi $api)
    {
    }

    public function name(): string
    {
        return 'whatsapp';
    }

    public function formats(): array
    {
        return ['plain'];
    }

    public function send(OutgoingMessage $message, int $fromPart, callable $onPartDelivered): SendResult
    {
        if ($fromPart >= 1) {
            return SendResult::sent(1);
        }

        try {
            $response = $this->api->sendText($message->target, $message->text);
        } catch (ExternalServiceException $e) {
            return $this->give(SendResult::retry(0, 1, $e->getMessage()));
        }

        if ($response->ok) {
            $onPartDelivered(1);

            return SendResult::sent(1);
        }

        if ($response->isRateLimited() || $response->isServerError()) {
            return $this->give(SendResult::retry(0, 1, $this->describe($response)), $response);
        }

        return $this->give(SendResult::failed(0, 1, $this->describe($response)), $response);
    }

    private function describe(EvolutionResponse $response): string
    {
        return mb_substr(trim(sprintf('whatsapp: HTTP %d %s', $response->status, $response->description)), 0, 250);
    }

    private function give(SendResult $result, ?EvolutionResponse $response = null): SendResult
    {
        Log::warning('WhatsApp message not delivered', [
            'outcome' => $result->outcome->value,
            'status' => $response?->status,
            'error' => $result->error,
        ]);

        return $result;
    }
}
