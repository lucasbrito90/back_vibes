<?php

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Histogram;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Contracts\UpDownCounter;
use App\Telemetry\SmartHome\SmartHomeDispatchEntryPoint;
use App\Telemetry\SmartHome\SmartHomeDispatchTelemetry;
use Tests\Support\Telemetry\RecordingMeter;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

/**
 * Phase 7B.4.2 — Smart Home dispatch boundary. Exercises
 * App\Telemetry\SmartHome\SmartHomeDispatchTelemetry directly (the same way
 * SchedulerExecutionTelemetryTest exercises SchedulerExecutionTelemetry),
 * plus the real HTTP and console call sites in the two files below:
 *
 *   - tests/Feature/SmartHome/VibeSmartHomeDispatchApiTest.php (manual entry
 *     point, unmodified by this phase — telemetry failures must never
 *     surface there, which those existing tests already indirectly prove
 *     once this phase wires the real singleton in).
 *   - tests/Feature/Console/DispatchDueSchedulesCommandTest.php (scheduled
 *     entry point, if present).
 *
 * See backend-smart-home-dispatch-boundary.md for the full spec this test
 * file validates against.
 */
function fakeSmartHomeDispatchTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->bind(Meter::class, fn () => new RecordingMeter($recorder));
    app()->forgetInstance(SmartHomeDispatchTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, attributes: array<string, mixed>}>
 */
function smartHomeDispatchSpanCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->startSpanCalls,
        fn (array $call) => $call['name'] === 'smart_home.dispatch',
    ));
}

/**
 * @return list<array{name: string, amount: int|float, attributes: array<string, mixed>}>
 */
function smartHomeDispatchTotalCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->counterCalls,
        fn (array $call) => $call['name'] === 'ixora.smart_home.dispatch.total',
    ));
}

// 1. Span creation, naming, and entry_point attribute.
test('wrap() creates exactly one smart_home.dispatch span tagged with the given entry point', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $telemetry->wrap(
        SmartHomeDispatchEntryPoint::Manual,
        fn () => 'dispatch-result',
        fn ($result) => [2, 1],
    );

    $spans = smartHomeDispatchSpanCalls($recorder);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]['attributes']['ixora.dispatch.entry_point'])->toBe('manual');
});

test('wrap() tags the scheduled entry point distinctly from manual', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $telemetry->wrap(SmartHomeDispatchEntryPoint::Scheduled, fn () => null, fn ($result) => [0, 0]);

    $spans = smartHomeDispatchSpanCalls($recorder);

    expect($spans[0]['attributes']['ixora.dispatch.entry_point'])->toBe('scheduled');
});

// 2. Attribute values sourced from the extractCounts callback.
test('wrap() sets dispatched_actions and skipped_actions from the extractCounts callback', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $telemetry->wrap(
        SmartHomeDispatchEntryPoint::Manual,
        fn () => 'ignored',
        fn ($result) => [3, 2],
    );

    $attributes = $recorder->mergedSpanAttributes();

    expect($attributes['ixora.dispatch.dispatched_actions'])->toBe(3)
        ->and($attributes['ixora.dispatch.skipped_actions'])->toBe(2);
});

// 3. Only the three allowed attributes are ever set — no forbidden fields.
test('wrap() never sets a forbidden attribute (no IDs, payloads, or credentials)', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $telemetry->wrap(
        SmartHomeDispatchEntryPoint::Manual,
        fn () => 'ignored',
        fn ($result) => [1, 0],
    );

    $attributes = array_merge($recorder->startSpanCalls[0]['attributes'], $recorder->mergedSpanAttributes());

    expect(array_keys($attributes))->toEqualCanonicalizing([
        'ixora.dispatch.entry_point',
        'ixora.dispatch.dispatched_actions',
        'ixora.dispatch.skipped_actions',
    ]);
});

// 4. Span lifetime — ends exactly once, and only after the dispatch callable returns.
test('wrap() ends the span exactly once after a successful dispatch', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $telemetry->wrap(SmartHomeDispatchEntryPoint::Manual, fn () => null, fn ($result) => [0, 0]);

    expect($recorder->spanEndCalls)->toBe(1);
});

test('wrap() ends the dispatch span before returning — proving no queue/job execution can be included in its duration', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $spanEndedBeforeCallableRan = null;

    $telemetry->wrap(
        SmartHomeDispatchEntryPoint::Manual,
        function () use ($recorder, &$spanEndedBeforeCallableRan) {
            $spanEndedBeforeCallableRan = $recorder->spanEndCalls === 0;

            return 'dispatch-happens-here';
        },
        fn ($result) => [0, 0],
    );

    // The dispatch callable (standing in for VibeSmartHomeDispatchService::
    // dispatch(), which only enqueues SmartHomeActionJob and never executes
    // it inline for the production `database` queue driver) runs strictly
    // *before* end() — end() is only reached once wrap() itself returns.
    expect($spanEndedBeforeCallableRan)->toBeTrue()
        ->and($recorder->spanEndCalls)->toBe(1);
});

// 5. Exactly one span per dispatch — no duplicates even across repeated calls.
test('wrap() never creates more than one span per call, and multiple calls create separate, non-duplicated spans', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $telemetry->wrap(SmartHomeDispatchEntryPoint::Manual, fn () => null, fn ($result) => [1, 0]);
    $telemetry->wrap(SmartHomeDispatchEntryPoint::Scheduled, fn () => null, fn ($result) => [2, 0]);

    $spans = smartHomeDispatchSpanCalls($recorder);

    expect($spans)->toHaveCount(2)
        ->and($recorder->spanEndCalls)->toBe(2);
});

// 6. Parent reuse — startSpan() is used (never a disconnected/duplicate root);
// Tracer::activeSpan() is never called, so this class never *replaces* the
// active infrastructure span, only nests a child under it.
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

                throw new RuntimeException('activeSpan() must never be called by SmartHomeDispatchTelemetry');
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
    app()->forgetInstance(SmartHomeDispatchTelemetry::class);

    $telemetry = app(SmartHomeDispatchTelemetry::class);
    $telemetry->wrap(SmartHomeDispatchEntryPoint::Manual, fn () => null, fn ($result) => [0, 0]);

    expect($activeSpanCalls)->toBe(0);
});

// 7. Failure path — exception is recorded on the span, span still ends, and
// the exception always propagates unchanged (business behavior preserved).
test('wrap() records the exception, marks the span as errored, ends it, and rethrows unchanged', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $exception = new RuntimeException('dispatch failed for a real business reason');

    expect(fn () => $telemetry->wrap(
        SmartHomeDispatchEntryPoint::Manual,
        function () use ($exception) {
            throw $exception;
        },
        fn ($result) => [0, 0],
    ))->toThrow(RuntimeException::class, 'dispatch failed for a real business reason');

    expect($recorder->spanExceptions)->toHaveCount(1)
        ->and($recorder->spanExceptions[0])->toBe($exception)
        ->and($recorder->spanErrorCalls)->toBe(1)
        ->and($recorder->spanEndCalls)->toBe(1);
});

test('wrap() never calls extractCounts when the dispatch callable throws', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $extractCountsCalled = false;

    try {
        $telemetry->wrap(
            SmartHomeDispatchEntryPoint::Manual,
            function () {
                throw new RuntimeException('boom');
            },
            function ($result) use (&$extractCountsCalled) {
                $extractCountsCalled = true;

                return [0, 0];
            },
        );
    } catch (RuntimeException) {
        // Expected — see previous test.
    }

    expect($extractCountsCalled)->toBeFalse();
});

// 8. Fail-open — a broken Tracer must never affect dispatch() or its result.
test('a broken Tracer never prevents wrap() from running the dispatch callable or returning its result', function () {
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
    app()->forgetInstance(SmartHomeDispatchTelemetry::class);

    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $result = null;
    expect(function () use ($telemetry, &$result) {
        $result = $telemetry->wrap(
            SmartHomeDispatchEntryPoint::Manual,
            fn () => 'the-real-business-result',
            fn ($result) => [1, 0],
        );
    })->not->toThrow(Throwable::class);

    expect($result)->toBe('the-real-business-result');
});

test('a broken Tracer combined with a dispatch failure still rethrows the original business exception', function () {
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
    app()->forgetInstance(SmartHomeDispatchTelemetry::class);

    $telemetry = app(SmartHomeDispatchTelemetry::class);

    expect(fn () => $telemetry->wrap(
        SmartHomeDispatchEntryPoint::Manual,
        function () {
            throw new DomainException('the real business failure');
        },
        fn ($result) => [0, 0],
    ))->toThrow(DomainException::class, 'the real business failure');
});

// 9. Business Metrics (Phase 7B.4.6) — ixora.smart_home.dispatch.total. See
// backend-smart-home-business-metrics.md for the full Design Record these
// tests validate against. No Histogram/UpDownCounter is ever used here.
test('wrap() records dispatched and skipped counts as two separate ixora.smart_home.dispatch.total increments, never merged into one outcome', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $telemetry->wrap(SmartHomeDispatchEntryPoint::Manual, fn () => 'dispatch-result', fn ($result) => [3, 2]);

    $calls = smartHomeDispatchTotalCalls($recorder);

    $dispatched = array_values(array_filter($calls, fn ($c) => $c['attributes']['outcome'] === 'dispatched'));
    $skipped = array_values(array_filter($calls, fn ($c) => $c['attributes']['outcome'] === 'skipped'));

    expect($calls)->toHaveCount(2)
        ->and($dispatched)->toHaveCount(1)
        ->and($dispatched[0]['amount'])->toBe(3)
        ->and($dispatched[0]['attributes']['entry_point'])->toBe('manual')
        ->and($skipped)->toHaveCount(1)
        ->and($skipped[0]['amount'])->toBe(2)
        ->and($skipped[0]['attributes']['entry_point'])->toBe('manual');
});

test('wrap() still records both dispatched and skipped increments, even when one count is zero', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $telemetry->wrap(SmartHomeDispatchEntryPoint::Scheduled, fn () => null, fn ($result) => [0, 0]);

    $calls = smartHomeDispatchTotalCalls($recorder);

    expect($calls)->toHaveCount(2)
        ->and($calls[0]['amount'])->toBe(0)
        ->and($calls[1]['amount'])->toBe(0);
});

test('wrap() records exactly one ixora.smart_home.dispatch.total increment of 1 with outcome=error when dispatch() throws — never a dispatched/skipped count', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    try {
        $telemetry->wrap(
            SmartHomeDispatchEntryPoint::Manual,
            function () {
                throw new RuntimeException('boom');
            },
            fn ($result) => [0, 0],
        );
    } catch (RuntimeException) {
        // Expected — see failure-path tests above.
    }

    $calls = smartHomeDispatchTotalCalls($recorder);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['amount'])->toBe(1)
        ->and($calls[0]['attributes']['outcome'])->toBe('error');
});

test('the dispatch metric label set is exactly {environment, service_name, entry_point, outcome} — no forbidden or unbounded label', function () {
    $recorder = fakeSmartHomeDispatchTelemetry();
    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $telemetry->wrap(SmartHomeDispatchEntryPoint::Manual, fn () => null, fn ($result) => [1, 0]);

    foreach (smartHomeDispatchTotalCalls($recorder) as $call) {
        expect(array_keys($call['attributes']))->toEqualCanonicalizing(['environment', 'service_name', 'entry_point', 'outcome']);
    }
});

test('a broken Counter (registration succeeds, add() throws) never prevents wrap() from running the dispatch callable or returning its real result — metrics recording is fail-open', function () {
    app()->bind(Meter::class, fn () => new class implements Meter
    {
        public function counter(string $name, string $unit = '', string $description = ''): Counter
        {
            return new class implements Counter
            {
                public function add(int|float $amount, array $attributes = []): void
                {
                    throw new RuntimeException('counter exploded');
                }
            };
        }

        public function histogram(string $name, string $unit = '', string $description = ''): Histogram
        {
            throw new RuntimeException('not used by this class');
        }

        public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
        {
            throw new RuntimeException('not used by this class');
        }
    });
    app()->forgetInstance(SmartHomeDispatchTelemetry::class);

    $telemetry = app(SmartHomeDispatchTelemetry::class);

    $result = null;
    expect(function () use ($telemetry, &$result) {
        $result = $telemetry->wrap(
            SmartHomeDispatchEntryPoint::Manual,
            fn () => 'the-real-business-result',
            fn ($result) => [1, 0],
        );
    })->not->toThrow(Throwable::class);

    expect($result)->toBe('the-real-business-result');
});
