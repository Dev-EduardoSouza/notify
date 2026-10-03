<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDocsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('notifications.docs_enabled')) {
            throw new ApiException(404, 'not_found', 'Resource not found.');
        }

        return $next($request);
    }
}
