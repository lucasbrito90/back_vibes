<?php

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\TelemetryManager;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Http\HttpRequestTelemetry;
use App\Telemetry\Http\HttpRouteNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Telemetry\RecordingMeter;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

/**
 * Phase 7B.1 — HTTP + Routing telemetry, exercised through
 * App\Http\Middleware\HttpTelemetryMiddleware exactly as it runs in
 * production (registered globally in bootstrap/app.php). Tracer and Meter
 * are swapped for in-memory Recording* fakes (tests/Support/Telemetry) so
 * assertions never depend on a real OpenTelemetry SDK, Collector,
 * Prometheus, or Tempo — per Part 8's "use fakes or in-memory Telemetry
 * implementations" instruction.
 */
function fakeHttpTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->bind(Meter::class, fn () => new RecordingMeter($recorder));
    app()->forgetInstance(HttpRequestTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, amount: int|float, attributes: array<string, mixed>}>
 */
function requestTotalCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->counterCalls,
        fn (array $call) => $call['name'] === 'ixora.http.server.request.total',
    ));
}

/**
 * @return list<array{name: string, value: int|float, attributes: array<string, mixed>}>
 */
function durationCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->histogramCalls,
        fn (array $call) => $call['name'] === 'ixora.http.server.duration',
    ));
}

// 1. Successful named route.
test('a successful request through a route with a dynamic segment is counted once with a stable route template and success outcome', function () {
    $recorder = fakeHttpTelemetry();

    Route::get('/__http_telemetry/items/{item}', fn (string $item) => response()->json(['item' => $item]))
        ->name('test.items.show');

    $response = $this->getJson('/__http_telemetry/items/42');

    $response->assertOk();

    $requests = requestTotalCalls($recorder);
    $durations = durationCalls($recorder);

    expect($requests)->toHaveCount(1)
        ->and($durations)->toHaveCount(1);

    $attributes = $requests[0]['attributes'];

    expect($attributes['http_route'])->toBe('/__http_telemetry/items/{item}')
        ->and($attributes['http_method'])->toBe('GET')
        ->and($attributes['status_code_class'])->toBe('2xx')
        ->and($attributes['outcome'])->toBe('success')
        ->and($attributes)->toHaveKeys(['environment', 'service_name']);

    expect($durations[0]['value'])->toBeFloat()
        ->and($durations[0]['value'])->toBeGreaterThanOrEqual(0.0);

    $spanAttributes = $recorder->mergedSpanAttributes();
    expect($spanAttributes['http.route'])->toBe('/__http_telemetry/items/{item}')
        ->and($spanAttributes['http.request.method'])->toBe('GET')
        ->and($spanAttributes['http.response.status_code'])->toBe(200)
        ->and($spanAttributes['ixora.http.outcome'])->toBe('success')
        ->and($recorder->spanEndCalls)->toBe(0);
});

// 2. 404. Reaches recordResponse(), not recordException() — Laravel's
// router raises NotFoundHttpException, but Illuminate\Routing\Pipeline
// (which wraps the *global* middleware stack too, see
// HttpTelemetryMiddleware's docblock) renders it into a 404 Response before
// this middleware's $next() call returns.
test('a request to an unmatched URI (404) is counted once with a bounded fallback route and client_error outcome', function () {
    $recorder = fakeHttpTelemetry();

    $response = $this->getJson('/__http_telemetry/this-route-does-not-exist');

    $response->assertNotFound();

    $requests = requestTotalCalls($recorder);
    expect($requests)->toHaveCount(1);

    $attributes = $requests[0]['attributes'];
    expect($attributes['http_route'])->toBe(HttpRouteNormalizer::UNMATCHED)
        ->and($attributes['outcome'])->toBe('client_error')
        ->and($attributes['status_code_class'])->toBe('4xx');
});

// 3. 405.
test('a request with the wrong HTTP method for a matched URI (405) is counted once with a bounded fallback route', function () {
    $recorder = fakeHttpTelemetry();

    Route::get('/__http_telemetry/method-check', fn () => response()->json(['ok' => true]));

    $response = $this->postJson('/__http_telemetry/method-check', []);

    $response->assertStatus(405);

    $requests = requestTotalCalls($recorder);
    expect($requests)->toHaveCount(1);

    $attributes = $requests[0]['attributes'];
    expect($attributes['http_route'])->toBe(HttpRouteNormalizer::UNMATCHED)
        ->and($attributes['outcome'])->toBe('client_error')
        ->and($attributes['status_code_class'])->toBe('4xx');
});

// 4. Validation failure.
test('a validation failure (422) is counted once, keeps the route template, and never exports the submitted value', function () {
    $recorder = fakeHttpTelemetry();

    Route::post('/__http_telemetry/validate', function (Request $request) {
        $request->validate(['email' => 'required|email']);

        return response()->json(['ok' => true]);
    })->name('test.validate');

    $response = $this->postJson('/__http_telemetry/validate', ['email' => 'not-an-email']);

    $response->assertStatus(422);

    $requests = requestTotalCalls($recorder);
    expect($requests)->toHaveCount(1);

    $attributes = $requests[0]['attributes'];
    expect($attributes['http_route'])->toBe('/__http_telemetry/validate')
        ->and($attributes['outcome'])->toBe('client_error')
        ->and($attributes['status_code_class'])->toBe('4xx');

    $serialized = json_encode($attributes).json_encode($recorder->mergedSpanAttributes());
    expect($serialized)->not->toContain('not-an-email');
});

// 5. Authentication failure — exercised against the app's real firebase.auth-protected route.
test('an authentication failure on a real Firebase-protected route is counted once and never exports the request token', function () {
    $recorder = fakeHttpTelemetry();

    $response = $this->getJson('/api/vibes', ['Authorization' => 'Bearer not-a-real-token']);

    $response->assertStatus(401);

    $requests = requestTotalCalls($recorder);
    expect($requests)->toHaveCount(1);

    $attributes = $requests[0]['attributes'];
    expect($attributes['outcome'])->toBe('client_error')
        ->and($attributes['status_code_class'])->toBe('4xx');

    $serialized = json_encode($attributes).json_encode($recorder->mergedSpanAttributes());
    expect($serialized)
        ->not->toContain('not-a-real-token')
        ->not->toContain('Bearer');
});

// 6. Server exception.
//
// Illuminate\Routing\Pipeline already catches an exception thrown inside a
// controller/closure and renders it into a Response *before* it reaches a
// global middleware (see HttpTelemetryMiddleware's docblock) — so this is
// recorded via recordResponse(), not recordException(), and observes the
// real 500 status directly rather than an estimate. The active span is
// still marked as an error via Span::setError(); the exception itself is
// recorded onto the span by the existing auto-instrumentation
// (ExceptionWatcher, reviewed in Part 1), which this test cannot exercise
// without the real ext-opentelemetry extension — see
// backend-http-routing-instrumentation.md §"Known limitations".
test('an unhandled server exception is counted once, marks the active span as an error, and preserves the original response', function () {
    $recorder = fakeHttpTelemetry();

    Route::get('/__http_telemetry/boom', function () {
        throw new RuntimeException('boom');
    });

    $response = $this->getJson('/__http_telemetry/boom');

    $response->assertStatus(500);

    $requests = requestTotalCalls($recorder);
    $durations = durationCalls($recorder);

    expect($requests)->toHaveCount(1)
        ->and($durations)->toHaveCount(1);

    $attributes = $requests[0]['attributes'];
    expect($attributes['outcome'])->toBe('server_error')
        ->and($attributes['status_code_class'])->toBe('5xx')
        ->and($attributes['http_route'])->toBe('/__http_telemetry/boom');

    expect($recorder->spanErrorCalls)->toBe(1)
        ->and($recorder->spanEndCalls)->toBe(0);
});

// HttpRequestTelemetry::recordException() — the middleware's defensive
// try/catch path. Not reachable through a real HTTP request in this
// application (see HttpTelemetryMiddleware's docblock: Illuminate\Routing\Pipeline
// always renders exceptions into a Response first, for every request), so
// it is exercised directly here to prove it behaves correctly if it is ever
// reached (e.g. a future global middleware, or a Laravel internals change).
test('recordException() records the raw exception on the active span, counts the request once, and never estimates the wrong status for a known HttpException', function () {
    $recorder = fakeHttpTelemetry();

    $telemetry = app(HttpRequestTelemetry::class);
    $request = Request::create('/__http_telemetry/direct/42', 'GET');
    $route = new Illuminate\Routing\Route('GET', '__http_telemetry/direct/{id}', fn () => null);
    $request->setRouteResolver(fn () => $route);

    $telemetry->recordException($request, new NotFoundHttpException, 12.5);

    $requests = requestTotalCalls($recorder);
    expect($requests)->toHaveCount(1);

    $attributes = $requests[0]['attributes'];
    expect($attributes['http_route'])->toBe('/__http_telemetry/direct/{id}')
        ->and($attributes['outcome'])->toBe('client_error')
        ->and($attributes['status_code_class'])->toBe('4xx');

    expect($recorder->spanExceptions)->toHaveCount(1)
        ->and($recorder->spanExceptions[0])->toBeInstanceOf(NotFoundHttpException::class)
        ->and($recorder->spanEndCalls)->toBe(0);
});

// 7. Collector unavailable — real Tracer/Meter bindings (not the fakes), pointed at an unreachable OTLP endpoint.
test('the HTTP response remains correct and no telemetry exception reaches the application when the OTLP Collector is unreachable', function () {
    config(['telemetry.otlp.endpoint' => 'http://127.0.0.1:1']);
    app()->forgetInstance(TelemetryManager::class);
    app()->forgetInstance(HttpRequestTelemetry::class);

    Route::get('/__http_telemetry/collector_down', fn () => response()->json(['ok' => true]));

    $response = $this->getJson('/__http_telemetry/collector_down');

    $response->assertOk()->assertJson(['ok' => true]);
})->throwsNoExceptions();

// 8. Cardinality safety.
test('dynamic route values, query strings, and identifiers never appear in metric labels or span attributes', function () {
    $recorder = fakeHttpTelemetry();

    Route::get('/__http_telemetry/users/{user}', fn (string $user) => response()->json(['ok' => true]))
        ->name('test.users.show');

    $response = $this->getJson('/__http_telemetry/users/999?secret_token=abc123&user_id=555');

    $response->assertOk();

    $requests = requestTotalCalls($recorder);
    expect($requests)->toHaveCount(1);

    $attributes = $requests[0]['attributes'];
    $serialized = json_encode($attributes).json_encode($recorder->mergedSpanAttributes());

    expect($attributes['http_route'])->toBe('/__http_telemetry/users/{user}')
        ->and($serialized)->not->toContain('999')
        ->not->toContain('secret_token')
        ->not->toContain('abc123')
        ->not->toContain('555');
});

// 9. Dependency rule enforcement — see tests/Unit/Telemetry/Http/HttpTelemetryDependencyRuleTest.php
// and the pre-existing generic tests/Unit/Telemetry/DependencyRuleTest.php (which already scans
// app/Http and app/Telemetry/Http since it walks every app/ subdirectory).

// 10. No double instrumentation.
test('a single request increments the request-total counter and duration histogram exactly once, never twice', function () {
    $recorder = fakeHttpTelemetry();

    Route::get('/__http_telemetry/single', fn () => response()->json(['ok' => true]));

    $this->getJson('/__http_telemetry/single')->assertOk();

    expect(requestTotalCalls($recorder))->toHaveCount(1)
        ->and(durationCalls($recorder))->toHaveCount(1);
});

test('telemetry failures inside HttpRequestTelemetry are swallowed and never surface as an HTTP error', function () {
    app()->bind(Tracer::class, fn () => new class implements Tracer
    {
        public function startSpan(string $name, array $attributes = []): Span
        {
            throw new RuntimeException('tracer exploded');
        }

        public function activeSpan(): Span
        {
            throw new RuntimeException('tracer exploded');
        }

        public function currentContext(): ?TraceContext
        {
            return null;
        }

        public function currentTraceId(): ?string
        {
            return null;
        }

        public function currentSpanId(): ?string
        {
            return null;
        }
    });
    app()->forgetInstance(HttpRequestTelemetry::class);

    Route::get('/__http_telemetry/broken_tracer', fn () => response()->json(['ok' => true]));

    $response = $this->getJson('/__http_telemetry/broken_tracer');

    $response->assertOk()->assertJson(['ok' => true]);
})->throwsNoExceptions();
