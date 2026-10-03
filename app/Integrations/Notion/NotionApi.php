<?php

declare(strict_types=1);

namespace App\Integrations\Notion;

use App\Integrations\ExternalServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

final class NotionApi
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $version,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        try {
            $response = $this->http
                ->connectTimeout(5)
                ->timeout($this->timeoutSeconds)
                ->withToken($this->token)
                ->withHeaders(['Notion-Version' => $this->version])
                ->acceptJson()
                ->get(rtrim($this->baseUrl, '/') . '/v1/' . ltrim($path, '/'), $query);
        } catch (ConnectionException $e) {
            throw ExternalServiceException::connection('notion', $e);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (!$response->successful()) {
            $detail = trim(sprintf('%s %s', $body['code'] ?? '', $body['message'] ?? ''));
            throw ExternalServiceException::http('notion', $response->status(), $detail);
        }

        return $body;
    }
}
