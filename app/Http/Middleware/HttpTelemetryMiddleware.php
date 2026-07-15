<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Telemetry\Http\HttpRequestTelemetry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The Phase 7B.1 HTTP lifecycle integration point (backend-http-routing-
 * instrumentation.md §"Middleware / request lifecycle").
 *
 * Registered once, globally, via bootstrap/app.php's `$middleware->append()`
 * — not on the `web` or `api` middleware group — so every request (web, API,
 * and the framework's `/up` health route) is measured exactly once,
 * regardless of which group or route eventually matches. Appending (rather
 * than prepending) places it as the innermost global middleware: the
 * narrowest framework boundary that still wraps routing itself, so it can
 * observe both "no route matched" (404/405) and "route matched" outcomes.
 *
 * This middleware never converts an exception into a response, never
 * swallows an exception, and never changes what would have been returned
 * or thrown without it — it only measures. Every telemetry failure is
 * caught inside HttpRequestTelemetry itself (telemetry-availability-policy.md).
 *
 * Verified finding (Part 1 / Part 5 review — see backend-http-routing-
 * instrumentation.md §"Middleware / request lifecycle"): Laravel's HTTP
 * Kernel builds its *global* middleware stack with Illuminate\Routing\Pipeline
 * (Illuminate\Foundation\Http\Kernel imports it, not the base
 * Illuminate\Pipeline\Pipeline) — the same class used for route-level
 * middleware. That Pipeline catches an exception at the nearest enclosing
 * pipe and renders it into a Response *before* returning control to any
 * outer pipe. Practically, this means every 404, 405, validation failure,
 * auth failure, and unhandled controller exception is already a Response
 * with the real status code by the time it reaches this — or any — global
 * middleware's $next() call; none of them throw here.
 *
 * The try/catch below is therefore a defensive fallback, not the primary
 * path: it protects against an exception thrown by this middleware's own
 * "before" logic, or by a hypothetical future global middleware/invocation
 * context that does not route through Illuminate\Routing\Pipeline. It
 * always rethrows unchanged.
 */
final class HttpTelemetryMiddleware
{
    public function __construct(private readonly HttpRequestTelemetry $telemetry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->telemetry->recordException($request, $exception, $this->elapsedMs($startedAt));

            throw $exception;
        }

        $this->telemetry->recordResponse($request, $response, $this->elapsedMs($startedAt));

        return $response;
    }

    private function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
