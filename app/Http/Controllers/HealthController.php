<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    #[Endpoint(operationId: 'health', title: 'Health check', description: 'Public endpoint used by uptime monitors. Requires no authentication.')]
    #[Response(200, 'Service is up.', type: 'array{status: "ok"}')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
