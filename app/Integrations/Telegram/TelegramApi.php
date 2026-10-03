<?php

declare(strict_types=1);

namespace App\Integrations\Telegram;

use App\Integrations\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

final class TelegramApi
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws ExternalServiceException on network failures only.
     */
    public function call(string $method, array $params): TelegramResponse
    {
        $url = sprintf('%s/bot%s/%s', rtrim($this->baseUrl, '/'), $this->token, $method);

        try {
            $response = $this->http
                ->connectTimeout(5)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post($url, $params);
        } catch (ConnectionException $e) {
            throw ExternalServiceException::connection('telegram', $e);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $parameters = is_array($body['parameters'] ?? null) ? $body['parameters'] : [];
        $result = is_array($body['result'] ?? null) ? $body['result'] : [];

        return new TelegramResponse(
            status: $response->status(),
            ok: $response->successful() && ($body['ok'] ?? false) === true,
            description: is_string($body['description'] ?? null) ? $body['description'] : '',
            retryAfter: is_int($parameters['retry_after'] ?? null) ? $parameters['retry_after'] : null,
            result: $result,
        );
    }
}
