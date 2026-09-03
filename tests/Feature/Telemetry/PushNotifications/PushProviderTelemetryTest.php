<?php

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\PushNotifications\PushProviderTelemetry;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

/**
 * Phase 7B.6 — Push Provider Execution boundary. Exercises
 * App\Telemetry\PushNotifications\PushProviderTelemetry directly, the same
 * way SmartHomeProviderTelemetryTest.php exercises SmartHomeProviderTelemetry.
 * Real wiring into FcmPushProvider::send() is covered by
 * tests/Unit/PushNotifications/FcmPushProviderTest.php.
 */
function fakePushProviderTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->forgetInstance(PushProviderTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, attributes: array<string, mixed>}>
 */
function pushProviderSpanCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->startSpanCalls,
        fn (array $call) => $call['name'] === 'push.provider',
    ));
}

test('wrap() creates exactly one push.provider span with no custom attributes', function () {
    $recorder = fakePushProviderTelemetry();
    $telemetry = app(PushProviderTelemetry::class);

    $telemetry->wrap(fn () => 'provider-result');

    $spans = pushProviderSpanCalls($recorder);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]['attributes'])->toBe([]);
});

test('wrap() never sets a forbidden attribute — no url, method, status code, duration, token, or credential', function () {
    $recorder = fakePushProviderTelemetry();
    $telemetry = app(PushProviderTelemetry::class);

    $telemetry->wrap(fn () => 'ignored');

    $attributes = array_merge($recorder->startSpanCalls[0]['attributes'], $recorder->mergedSpanAttributes());

    expect($attributes)->toBe([]);
});

test('wrap() ends the span exactly once after a successful execution', function () {
    $recorder = fakePushProviderTelemetry();
    $telemetry = app(PushProviderTelemetry::class);

    $telemetry->wrap(fn () => null);

    expect($recorder->spanEndCalls)->toBe(1);
});

test('wrap() runs execute() strictly before ending the span — proving the HTTP call is fully contained inside the span', function () {
    $recorder = fakePushProviderTelemetry();
    $telemetry = app(PushProviderTelemetry::class);

    $spanWasStillOpenDuringExecute = null;

    $telemetry->wrap(function () use ($recorder, &$spanWasStillOpenDuringExecute) {
        $spanWasStillOpenDuringExecute = $recorder->spanEndCalls === 0;

        return 'http-call-happens-here';
    });

    expect($spanWasStillOpenDuringExecute)->toBeTrue()
        ->and($recorder->spanEndCalls)->toBe(1);
});

test('wrap() never creates more than one span per call, and multiple calls create separate, non-duplicated spans', function () {
    $recorder = fakePushProviderTelemetry();
    $telemetry = app(PushProviderTelemetry::class);

    $telemetry->wrap(fn () => null);
    $telemetry->wrap(fn () => null);

    $spans = pushProviderSpanCalls($recorder);

    expect($spans)->toHaveCount(2)
        ->and($recorder->spanEndCalls)->toBe(2);
});

test('wrap() creates a child span via Tracer::startSpan(), never enriches or replaces the active span', function () {
    $activeSpanCalls = 0;

    app()->bind(Tracer::class, function () use (&$activeSpanCalls) {
        return new class($activeSpanCalls) implements Tracer
        {
            public function __construct(private int &$activeSpanCalls) {}

            public function startSpan(string $name, array $attributes = []): Span
            {
                return new class implements Span
                {
                    public function setAttribute(string $key, $value): static
                    {
                        return $this;
                    }

                    public function setAttributes(array $attributes): static
                    {
                        return $this;
                    }

                    public function addEvent(string $name, array $attributes = []): static
                    {
                        return $this;
                    }

                    public function recordException(Throwable $exception): static
                    {
                        return $this;
                    }

                    public function setError(?string $description = null): static
                    {
                        return $this;
                    }

                    public function end(): void {}
                };
            }

            public function activeSpan(): Span
            {
                $this->activeSpanCalls++;

                throw new RuntimeException('activeSpan() must never be called by PushProviderTelemetry');
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
        };
    });
    app()->forgetInstance(PushProviderTelemetry::class);

    $telemetry = app(PushProviderTelemetry::class);
    $telemetry->wrap(fn () => null);

    expect($activeSpanCalls)->toBe(0);
});

test('wrap() records the exception, marks the span as errored, ends it, and rethrows unchanged', function () {
    $recorder = fakePushProviderTelemetry();
    $telemetry = app(PushProviderTelemetry::class);

    $exception = new RuntimeException('FCM OAuth token exchange blew up for a real reason');

    expect(fn () => $telemetry->wrap(function () use ($exception) {
        throw $exception;
    }))->toThrow(RuntimeException::class, 'FCM OAuth token exchange blew up for a real reason');

    expect($recorder->spanExceptions)->toHaveCount(1)
        ->and($recorder->spanExceptions[0])->toBe($exception)
        ->and($recorder->spanErrorCalls)->toBe(1)
        ->and($recorder->spanEndCalls)->toBe(1);
});

test('wrap() does not mark the span as errored for a normally-returned failure value', function () {
    $recorder = fakePushProviderTelemetry();
    $telemetry = app(PushProviderTelemetry::class);

    $telemetry->wrap(fn () => new class
    {
        public bool $success = false;
    });

    expect($recorder->spanErrorCalls)->toBe(0)
        ->and($recorder->spanExceptions)->toBe([])
        ->and($recorder->spanEndCalls)->toBe(1);
});

test('a broken Tracer never prevents wrap() from running execute() or returning its result', function () {
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
    app()->forgetInstance(PushProviderTelemetry::class);

    $telemetry = app(PushProviderTelemetry::class);

    $result = null;
    expect(function () use ($telemetry, &$result) {
        $result = $telemetry->wrap(fn () => 'the-real-http-result');
    })->not->toThrow(Throwable::class);

    expect($result)->toBe('the-real-http-result');
});

test('a broken Tracer combined with a business failure still rethrows the original exception unchanged', function () {
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
    app()->forgetInstance(PushProviderTelemetry::class);

    $telemetry = app(PushProviderTelemetry::class);

    expect(fn () => $telemetry->wrap(function () {
        throw new DomainException('the real FCM send failure');
    }))->toThrow(DomainException::class, 'the real FCM send failure');
});

test('wrap() never records a counter, histogram, or up-down counter', function () {
    $recorder = fakePushProviderTelemetry();
    $telemetry = app(PushProviderTelemetry::class);

    $telemetry->wrap(fn () => null);

    expect($recorder->counterCalls)->toBe([])
        ->and($recorder->histogramCalls)->toBe([])
        ->and($recorder->upDownCounterCalls)->toBe([]);
});
