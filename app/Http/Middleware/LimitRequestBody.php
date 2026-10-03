<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitRequestBody
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('notifications.max_body_bytes');
        $declared = (int) $request->server('CONTENT_LENGTH', 0);

        if ($declared > $limit || strlen($request->getContent()) > $limit) {
            throw new ApiException(413, 'payload_too_large', sprintf('The request body exceeds %d bytes.', $limit));
        }

        return $next($request);
    }
}
