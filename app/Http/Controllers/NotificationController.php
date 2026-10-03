<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\RecipientResolver;
use App\Notifications\Channels\UnknownRecipientException;
use App\Notifications\DeliveryStatus;
use App\Notifications\IdempotencyConflictException;
use App\Notifications\NotificationRequest;
use App\Notifications\NotificationService;
use App\Notifications\UnsupportedFormatException;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class NotificationController
{
    public const MAX_TEXT_LENGTH = 20000;

    private const FIELDS = ['recipient', 'channel', 'text', 'format'];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ChannelRegistry $channels,
    ) {
    }

    #[Endpoint(operationId: 'sendNotification', title: 'Send a notification', description: 'Delivers the text synchronously through the chosen channel. Long texts are split into several messages. Temporary failures (rate limit, channel outage) return 202 and are retried automatically.')]
    #[HeaderParameter('Idempotency-Key', 'Optional. Repeating the same key with the same body returns the original notification without sending again; a different body returns 422.', required: false, type: 'string', example: 'order-123-paid')]
    #[BodyParameter('recipient', 'Recipient alias allowed for this API key.', required: true, type: 'string', example: 'eduardo')]
    #[BodyParameter('channel', 'Delivery channel.', required: true, type: 'string', example: 'telegram')]
    #[BodyParameter('text', 'Message text, up to 20000 characters.', required: true, type: 'string', example: 'Pedido 123 pago.')]
    #[BodyParameter('format', 'plain (default) or html (Telegram HTML subset).', required: false, type: 'string', example: 'plain')]
    #[IgnoreResponse(200)]
    #[Response(201, 'Delivered.', type: 'DeliveryResource')]
    #[Response(202, 'Accepted; delivery will be retried automatically.', type: 'DeliveryResource')]
    #[Response(400, 'Body is not a JSON object.', type: 'array{code: string, message: string}')]
    #[Response(401, 'Missing or invalid API key.', type: 'array{code: string, message: string}')]
    #[Response(403, 'Recipient not allowed for this API key.', type: 'array{code: string, message: string}')]
    #[Response(413, 'Body too large.', type: 'array{code: string, message: string}')]
    #[Response(422, 'Invalid body, unknown recipient, unsupported format or Idempotency-Key conflict.', type: 'array{code: string, message: string, errors?: array<string, list<string>>}')]
    #[Response(429, 'Rate limit exceeded; see Retry-After.', type: 'array{code: string, message: string}')]
    #[Response(502, 'The channel rejected the notification permanently.', type: 'array{code: string, message: string, delivery_id: string}')]
    public function store(Request $request): JsonResponse
    {
        $key = AuthenticateApiKey::keyFrom($request);
        $data = $this->validated($this->jsonBody($request), $request->header('Idempotency-Key'));

        if (!$key->allowsRecipient($data['recipient'])) {
            throw new ApiException(403, 'forbidden', 'This API key is not allowed to send to this recipient.');
        }

        try {
            $result = $this->notifications->dispatch(NotificationRequest::forApiKey(
                $key->id,
                $data['channel'],
                $data['recipient'],
                $data['text'],
                $data['format'],
                $data['idempotency_key'],
            ));
        } catch (UnknownRecipientException) {
            throw new ApiException(422, 'unknown_recipient', 'The recipient is not configured for this channel.');
        } catch (UnsupportedFormatException $e) {
            throw new ApiException(422, 'unsupported_format', $e->getMessage());
        } catch (IdempotencyConflictException $e) {
            throw new ApiException(422, 'idempotency_conflict', $e->getMessage());
        }

        $response = $this->respond($result->delivery);
        if ($result->replayed) {
            $response->headers->set('Idempotent-Replayed', 'true');
        }

        return $response;
    }

    #[Endpoint(operationId: 'getNotification', title: 'Get a notification', description: 'Returns the delivery status. Only the API key that created the notification can read it.')]
    #[Response(200, 'Current delivery status.', type: 'DeliveryResource')]
    #[Response(401, 'Missing or invalid API key.', type: 'array{code: string, message: string}')]
    #[Response(404, 'Not found or created by another key.', type: 'array{code: string, message: string}')]
    #[Response(429, 'Rate limit exceeded; see Retry-After.', type: 'array{code: string, message: string}')]
    public function show(Request $request, string $id): JsonResponse
    {
        $key = AuthenticateApiKey::keyFrom($request);
        $id = strtolower($id);

        $delivery = preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $id) === 1
            ? Delivery::query()->where('client_ref', $key->clientRef())->find($id)
            : null;

        if ($delivery === null) {
            throw new ApiException(404, 'not_found', 'Notification not found.');
        }

        return (new DeliveryResource($delivery))->response();
    }

    private function respond(Delivery $delivery): JsonResponse
    {
        if ($delivery->status === DeliveryStatus::Failed) {
            return new JsonResponse([
                'code' => 'delivery_failed',
                'message' => 'The channel rejected the notification.',
                'delivery_id' => $delivery->id,
                'notification' => (new DeliveryResource($delivery))->resolve(),
            ], 502);
        }

        $status = $delivery->status === DeliveryStatus::Sent ? 201 : 202;

        return (new DeliveryResource($delivery))->response()->setStatusCode($status);
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
     * @return array{recipient: string, channel: string, text: string, format: string, idempotency_key: string|null}
     */
    private function validated(array $body, ?string $idempotencyKey): array
    {
        $unknown = array_diff(array_keys($body), self::FIELDS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => [sprintf('Unknown field(s): %s.', implode(', ', $unknown))]]);
        }

        $validator = Validator::make($body + ['idempotency_key' => $idempotencyKey], [
            'recipient' => ['required', 'string', 'regex:' . RecipientResolver::ALIAS_PATTERN],
            'channel' => ['required', 'string', Rule::in($this->channels->names())],
            'text' => ['required', 'string', 'max:' . self::MAX_TEXT_LENGTH, 'not_regex:/^\s*$/u'],
            'format' => ['sometimes', 'string', Rule::in(['plain', 'html'])],
            'idempotency_key' => ['nullable', 'string', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
        ]);

        $data = $validator->validate();

        return [
            'recipient' => (string) $data['recipient'],
            'channel' => (string) $data['channel'],
            'text' => (string) $data['text'],
            'format' => isset($data['format']) ? (string) $data['format'] : 'plain',
            'idempotency_key' => isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null,
        ];
    }
}
