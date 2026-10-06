<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\ClientKey;
use App\Auth\ClientKeyRegistry;
use App\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateApiKey
{
    public const ATTRIBUTE = 'client_key';

    public function __construct(private readonly ClientKeyRegistry $keys)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $key = $token === null ? null : $this->keys->authenticate($token);

        if ($key === null) {
            throw new ApiException(401, 'unauthorized', 'Missing or invalid API key.', ['WWW-Authenticate' => 'Bearer']);
        }

        $request->attributes->set(self::ATTRIBUTE, $key);

        return $next($request);
    }

    public static function keyFrom(Request $request): ClientKey
    {
        $key = $request->attributes->get(self::ATTRIBUTE);
        if (!$key instanceof ClientKey) {
            throw new ApiException(401, 'unauthorized', 'Missing or invalid API key.');
        }

        return $key;
    }
}
