<?php

declare(strict_types=1);

namespace App\Integrations\Evolution;

use App\Integrations\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

final class EvolutionApi
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $instance,
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function sendText(string $number, string $text): EvolutionResponse
    {
        $url = sprintf('%s/message/sendText/%s', rtrim($this->baseUrl, '/'), rawurlencode($this->instance));

        try {
            $response = $this->http
                ->connectTimeout(5)
                ->timeout($this->timeoutSeconds)
                ->withHeaders(['apikey' => $this->apiKey])
                ->acceptJson()
                ->asJson()
                ->post($url, ['number' => $number, 'text' => $text]);
        } catch (ConnectionException $e) {
            throw ExternalServiceException::connection('whatsapp', $e);
        }

        $body = $response->json();

        return new EvolutionResponse(
            status: $response->status(),
            ok: $response->successful(),
            description: $this->describe(is_array($body) ? $body : []),
        );
    }

    /** @param array<mixed> $body */
    private function describe(array $body): string
    {
        $messages = is_array($body['response'] ?? null) ? ($body['response']['message'] ?? null) : null;
        if (is_array($messages)) {
            foreach ($messages as $entry) {
                if (is_array($entry) && ($entry['exists'] ?? null) === false) {
                    return 'recipient is not on WhatsApp';
                }
            }
        }

        return is_string($body['error'] ?? null) ? mb_substr($body['error'], 0, 100) : '';
    }
}
