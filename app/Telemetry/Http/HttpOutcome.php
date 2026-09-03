<?php

declare(strict_types=1);

namespace App\Telemetry\Http;

/**
 * Bounded classification of an HTTP request's result, used as the `outcome`
 * label on ixora.http.server.* metrics and as the `ixora.http.outcome` span
 * attribute (backend-http-routing-instrumentation.md §"Metrics"). Never
 * derived from anything unbounded (no raw exception messages, no route
 * parameters) — only from the HTTP status code family.
 *
 * `Cancelled` is part of the bounded set for forward compatibility with
 * other Ixora domains (queue/console cancellation in Phase 7B.2+) but is
 * never produced by fromStatusCode() today — plain PHP-FPM/Laravel HTTP
 * handling has no standard, safe signal for "client disconnected before the
 * response was written" distinct from a normal completed response.
 */
enum HttpOutcome: string
{
    case Success = 'success';
    case ClientError = 'client_error';
    case ServerError = 'server_error';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public static function fromStatusCode(int $statusCode): self
    {
        return match (true) {
            $statusCode >= 200 && $statusCode < 400 => self::Success,
            $statusCode >= 400 && $statusCode < 500 => self::ClientError,
            $statusCode >= 500 && $statusCode < 600 => self::ServerError,
            default => self::Unknown,
        };
    }

    /**
     * Bounded status-code family used for the `status_code_class` label
     * (e.g. "2xx", "4xx") — never the raw status code.
     */
    public static function statusCodeClass(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 100 && $statusCode < 600 => intdiv($statusCode, 100).'xx',
            default => 'unknown',
        };
    }
}
