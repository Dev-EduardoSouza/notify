<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ErrorResponder
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        422 => 'validation_failed',
        429 => 'rate_limited',
        502 => 'delivery_failed',
        503 => 'unavailable',
    ];

    private const MESSAGES = [
        404 => 'Resource not found.',
        405 => 'Method not allowed.',
        429 => 'Too many requests.',
        503 => 'Service unavailable.',
    ];

    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::json($e->status, $e->errorCode, $e->getMessage(), $e->headers, $e->extra),
            $e instanceof ValidationException => self::json(422, 'validation_failed', 'The request body is invalid.', [], ['errors' => $e->errors()]),
            $e instanceof ThrottleRequestsException => self::json(429, 'rate_limited', self::MESSAGES[429], self::stringHeaders($e->getHeaders())),
            $e instanceof HttpExceptionInterface => self::fromHttp($e),
            default => self::json(500, 'internal_error', 'Internal server error.'),
        };
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $extra
     */
    public static function json(int $status, string $code, string $message, array $headers = [], array $extra = []): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'message' => $message] + $extra, $status, $headers);
    }

    private static function fromHttp(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        return self::json(
            $status,
            self::CODES[$status] ?? 'http_error',
            self::MESSAGES[$status] ?? 'Request failed.',
            self::stringHeaders($e->getHeaders()),
        );
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        $clean = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $clean[$name] = (string) $value;
            }
        }

        return $clean;
    }
}
