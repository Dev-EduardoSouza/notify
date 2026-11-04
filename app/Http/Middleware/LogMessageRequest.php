<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\ClientKey;
use App\Notifications\Channels\RecipientResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class LogMessageRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $result = json_decode((string) $response->getContent(), true);
        $result = is_array($result) ? $result : [];
        $key = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);

        Log::info('message request', array_filter([
            'key' => $key instanceof ClientKey ? $key->name : null,
            'channel' => $this->safe($body['channel'] ?? null, '/^[a-z0-9_-]{1,32}$/'),
            'to' => $this->safe($body['to'] ?? null, RecipientResolver::ALIAS_PATTERN),
            'status' => $response->getStatusCode(),
            'parts' => is_int($result['parts'] ?? null) ? $result['parts'] : null,
            'queued_id' => is_int($result['id'] ?? null) ? $result['id'] : null,
            'code' => is_string($result['code'] ?? null) ? $result['code'] : null,
        ], fn (mixed $value): bool => $value !== null));

        return $response;
    }

    private function safe(mixed $value, string $pattern): ?string
    {
        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }
}
