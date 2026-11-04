<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Messages\ChannelDeliveryException;
use App\Messages\MessageDispatcher;
use App\Messages\MessageEnqueuer;
use App\Notifications\Channels\RecipientResolver;
use App\Notifications\Channels\UnknownChannelException;
use App\Notifications\Channels\UnknownRecipientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class MessageController
{
    public const MAX_TEXT_LENGTH = 20000;

    private const FIELDS = ['channel', 'to', 'text'];

    public function __construct(
        private readonly MessageDispatcher $dispatcher,
        private readonly MessageEnqueuer $enqueuer,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $key = AuthenticateApiKey::keyFrom($request);
        $message = $this->validated($this->jsonBody($request));

        if (!$key->allowsChannel($message['channel'])) {
            throw new ApiException(403, 'channel_not_allowed', 'This API key is not allowed to use this channel.');
        }
        if (!$key->allowsRecipient($message['to'])) {
            throw new ApiException(403, 'recipient_not_allowed', 'This API key is not allowed to send to this recipient.');
        }

        try {
            if ($this->enqueuer->handles($message['channel'])) {
                $id = $this->enqueuer->enqueue($key->name, $message['channel'], $message['to'], $message['text']);

                return new JsonResponse(['status' => 'queued', 'id' => $id], 202);
            }

            $parts = $this->dispatcher->send($message['channel'], $message['to'], $message['text']);
        } catch (UnknownChannelException) {
            throw new ApiException(422, 'unknown_channel', 'The channel is not available.');
        } catch (UnknownRecipientException) {
            throw new ApiException(422, 'unknown_recipient', 'The recipient is not configured for this channel.');
        } catch (ChannelDeliveryException $e) {
            throw new ApiException(502, 'channel_failed', $e->getMessage());
        }

        return new JsonResponse(['status' => 'sent', 'parts' => $parts]);
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        if (!$request->isJson()) {
            throw new ApiException(400, 'bad_request', 'The request body must be JSON (Content-Type: application/json).');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException(400, 'bad_request', 'The request body must be a valid JSON object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{channel: string, to: string, text: string}
     */
    private function validated(array $body): array
    {
        $unknown = array_diff(array_keys($body), self::FIELDS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => [sprintf('Unknown field(s): %s.', implode(', ', $unknown))]]);
        }

        $data = Validator::make($body, [
            'channel' => ['required', 'string', 'max:32'],
            'to' => ['required', 'string', 'regex:' . RecipientResolver::ALIAS_PATTERN],
            'text' => ['required', 'string', 'max:' . self::MAX_TEXT_LENGTH],
        ])->validate();

        return [
            'channel' => (string) $data['channel'],
            'to' => (string) $data['to'],
            'text' => (string) $data['text'],
        ];
    }
}
