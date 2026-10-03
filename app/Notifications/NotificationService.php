<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Delivery;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\OutgoingMessage;
use App\Notifications\Channels\RecipientResolver;
use App\Notifications\Channels\SendOutcome;
use App\Notifications\Channels\SendResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class NotificationService
{
    public const MAX_ATTEMPTS = 5;

    public const BACKOFF_MINUTES = [5, 15, 30, 60];

    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly RecipientResolver $recipients,
    ) {
    }

    public function dispatch(NotificationRequest $request): DispatchResult
    {
        $channel = $this->channels->get($request->channel);
        if (!in_array($request->format, $channel->formats(), true)) {
            throw new UnsupportedFormatException($request->channel, $request->format);
        }
        $this->recipients->resolve($request->channel, $request->recipient);

        $existing = $this->findReplay($request);
        if ($existing !== null) {
            return new DispatchResult($existing, true);
        }

        try {
            $delivery = Delivery::create([
                'client_ref' => $request->clientRef,
                'api_key_id' => $request->apiKeyId,
                'idempotency_key' => $request->idempotencyKey,
                'request_hash' => $request->hash(),
                'channel' => $request->channel,
                'recipient' => $request->recipient,
                'format' => $request->format,
                'text' => $request->text,
                'status' => DeliveryStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            $winner = $this->findReplay($request);
            if ($winner === null) {
                throw new \LogicException('Unique violation without a matching delivery.');
            }

            return new DispatchResult($winner, true);
        }

        return new DispatchResult($this->attempt($delivery), false);
    }

    public function attempt(Delivery $delivery): Delivery
    {
        if ($delivery->status->isFinal() || $delivery->text === null) {
            return $delivery;
        }

        $channel = $this->channels->get($delivery->channel);
        $target = $this->recipients->resolve($delivery->channel, $delivery->recipient);

        $delivery->attempts++;
        $delivery->save();

        $result = $channel->send(
            new OutgoingMessage($target, $delivery->text, $delivery->format),
            $delivery->delivered_parts,
            function (int $delivered) use ($delivery): void {
                $delivery->delivered_parts = $delivered;
                $delivery->save();
            },
        );

        $this->record($delivery, $result);

        Log::info('Delivery attempt finished', [
            'delivery_id' => $delivery->id,
            'client_ref' => $delivery->client_ref,
            'channel' => $delivery->channel,
            'status' => $delivery->status->value,
            'attempt' => $delivery->attempts,
            'parts' => $delivery->delivered_parts . '/' . $delivery->parts_total,
        ]);

        return $delivery;
    }

    private function record(Delivery $delivery, SendResult $result): void
    {
        $delivery->parts_total = $result->totalParts;
        $delivery->delivered_parts = $result->deliveredParts;
        $delivery->last_error = $result->error;

        if ($result->outcome === SendOutcome::Sent) {
            $delivery->status = DeliveryStatus::Sent;
            $delivery->sent_at = Carbon::now();
            $delivery->next_attempt_at = null;
            $delivery->text = null;
        } elseif ($result->outcome === SendOutcome::Retry && $delivery->attempts < self::MAX_ATTEMPTS) {
            $backoff = self::BACKOFF_MINUTES[$delivery->attempts - 1] * 60;
            $delivery->status = DeliveryStatus::Retrying;
            $delivery->next_attempt_at = Carbon::now()->addSeconds(max($backoff, $result->retryAfterSeconds ?? 0));
        } else {
            $delivery->status = DeliveryStatus::Failed;
            $delivery->failed_at = Carbon::now();
            $delivery->next_attempt_at = null;
            $delivery->text = null;
        }

        $delivery->save();
    }

    private function findReplay(NotificationRequest $request): ?Delivery
    {
        if ($request->idempotencyKey === null) {
            return null;
        }

        $existing = Delivery::query()
            ->where('client_ref', $request->clientRef)
            ->where('idempotency_key', $request->idempotencyKey)
            ->first();

        if ($existing !== null && !hash_equals($existing->request_hash, $request->hash())) {
            throw new IdempotencyConflictException();
        }

        return $existing;
    }
}
