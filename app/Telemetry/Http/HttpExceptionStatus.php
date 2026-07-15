<?php

declare(strict_types=1);

namespace App\Telemetry\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Best-effort status-code estimation for an exception observed before
 * Laravel has rendered it into a Response — used by both
 * HttpRequestTelemetry (metrics/span) and HttpErrorContextLogTap (log
 * enrichment), which both see the raw exception rather than the final
 * response. Mirrors (a bounded subset of) the exception-to-status mapping
 * Illuminate's own exception handler applies. Never affects the actual HTTP
 * response — only the accuracy of telemetry and log context for this one
 * request.
 */
final class HttpExceptionStatus
{
    public static function estimate(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
            $exception instanceof AuthorizationException => Response::HTTP_FORBIDDEN,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }
}
