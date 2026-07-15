<?php

declare(strict_types=1);

namespace App\Telemetry\Http;

use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Histogram;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Tracer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * The Ixora-specific layer on top of the HTTP server span that
 * opentelemetry-auto-laravel already starts (backend-http-routing-
 * instrumentation.md §"Existing auto-instrumentation review"). Records the
 * two minimum HTTP metrics (Part 3) and enriches the active span with safe,
 * bounded attributes (Part 4) — never creates a span, never alters the
 * request/response, never throws.
 *
 * Consumes only App\Telemetry\Contracts\Tracer and Meter, per the Phase
 * 7B.1 architecture rule — no OpenTelemetry SDK import anywhere in this
 * class or elsewhere in app/Telemetry/Http.
 */
final class HttpRequestTelemetry
{
    private const METRIC_REQUEST_TOTAL = 'ixora.http.server.request.total';

    private const METRIC_DURATION = 'ixora.http.server.duration';

    /**
     * Milliseconds — matches the unit already documented for
     * ixora.http.server.duration in telemetry-naming-convention.md §"Official
     * examples", chosen platform-wide for this metric.
     */
    private const DURATION_UNIT = 'ms';

    private readonly Counter $requestTotal;

    private readonly Histogram $duration;

    public function __construct(
        private readonly Tracer $tracer,
        Meter $meter,
        private readonly HttpRouteNormalizer $routeNormalizer,
        private readonly string $environment,
        private readonly string $serviceName,
    ) {
        $this->requestTotal = $meter->counter(
            self::METRIC_REQUEST_TOTAL,
            unit: '{request}',
            description: 'Total HTTP requests received, labeled by method, route, status class, and outcome.',
        );

        $this->duration = $meter->histogram(
            self::METRIC_DURATION,
            unit: self::DURATION_UNIT,
            description: 'HTTP request duration in milliseconds, labeled by method, route, status class, and outcome.',
        );
    }

    /**
     * Records a request that completed with a response — in practice, this
     * is the path taken by *every* request Laravel's HTTP Kernel handles,
     * including 404, 405, validation failures (422), auth failures
     * (401/403), and unhandled controller exceptions rendered to a 5xx.
     * Illuminate\Routing\Pipeline (used for both the global and route-level
     * middleware stacks — see HttpTelemetryMiddleware's docblock) always
     * renders an exception into a Response before it can reach a global
     * middleware, so this method always observes the real, final status
     * code rather than an estimate. The active span is still marked as an
     * error for 5xx responses via Span::setError() — the underlying
     * exception itself is recorded onto the span by the existing
     * auto-instrumentation (ExceptionWatcher, reviewed in Part 1), not
     * duplicated here.
     */
    public function recordResponse(Request $request, SymfonyResponse $response, float $durationMs): void
    {
        $this->safely(fn () => $this->record(
            request: $request,
            statusCode: $response->getStatusCode(),
            durationMs: $durationMs,
            exception: null,
        ));
    }

    /**
     * Records a request that failed via a raw exception reaching this
     * middleware directly, without Laravel having rendered it into a
     * Response first. Verified (Part 1 / Part 5 review) not to happen for
     * any request Illuminate\Routing\Pipeline handles in this application
     * today — kept as a defensive, correctly-behaving path for
     * HttpTelemetryMiddleware's own fallback try/catch, exercised directly
     * in tests rather than through a real HTTP request.
     */
    public function recordException(Request $request, Throwable $exception, float $durationMs): void
    {
        $this->safely(fn () => $this->record(
            request: $request,
            statusCode: HttpExceptionStatus::estimate($exception),
            durationMs: $durationMs,
            exception: $exception,
        ));
    }

    private function record(Request $request, int $statusCode, float $durationMs, ?Throwable $exception): void
    {
        $route = $this->routeNormalizer->normalize($request);
        $outcome = HttpOutcome::fromStatusCode($statusCode);
        $statusClass = HttpOutcome::statusCodeClass($statusCode);

        $labels = [
            'environment' => $this->environment,
            'service_name' => $this->serviceName,
            'http_method' => $request->method(),
            'http_route' => $route,
            'status_code_class' => $statusClass,
            'outcome' => $outcome->value,
        ];

        $this->requestTotal->add(1, $labels);
        $this->duration->record($durationMs, $labels);

        $span = $this->tracer->activeSpan();
        $span->setAttributes([
            'http.request.method' => $request->method(),
            'http.route' => $route,
            'http.response.status_code' => $statusCode,
            'ixora.http.outcome' => $outcome->value,
            'url.scheme' => $request->getScheme(),
            'server.address' => $request->getHost(),
        ]);

        if ($exception !== null) {
            $span->recordException($exception);
        } elseif ($outcome === HttpOutcome::ServerError) {
            $span->setError();
        }
    }

    /**
     * Telemetry must never affect a request (telemetry-availability-policy.md).
     */
    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            // Intentionally swallowed — see class docblock.
        }
    }
}
