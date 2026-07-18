<?php

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use App\Telemetry\SmartHome\SmartHomeActionProvider;
use App\Telemetry\SmartHome\SmartHomeActionTelemetry;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

/**
 * Phase 7B.4.3 — Smart Home Action Execution boundary. Exercises
 * App\Telemetry\SmartHome\SmartHomeActionTelemetry directly (the same way
 * SmartHomeDispatchTelemetryTest.php exercises SmartHomeDispatchTelemetry),
 * plus the real wiring into SmartHomeActionJob::handle() in
 * tests/Feature/SmartHome/SmartHomeActionJobTest.php (unmodified business
 * assertions in that file already indirectly prove fail-open once this
 * phase wires the real singleton in) and the dedicated boundary-integration
 * test in SmartHomeActionBoundaryIntegrationTest.php.
 *
 * See backend-smart-home-action-execution.md for the full spec this test
 * file validates against.
 */
function fakeSmartHomeActionTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->forgetInstance(SmartHomeActionTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, attributes: array<string, mixed>}>
 */
function smartHomeActionSpanCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->startSpanCalls,
        fn (array $call) => $call['name'] === 'smart_home.action',
    ));
}

function noopClassifyResult(): callable
{
    return fn ($result) => SmartHomeActionOutcome::Success;
}

function noopClassifyException(): callable
{
    return fn (Throwable $e) => SmartHomeActionOutcome::Failure;
}

// 1. Span creation, naming, and provider/retry attributes.
test('wrap() creates exactly one smart_home.action span tagged with the given provider', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $telemetry->wrap(
        SmartHomeActionProvider::HomeAssistant,
        false,
        fn () => 'action-result',
        noopClassifyResult(),
        noopClassifyException(),
    );

    $spans = smartHomeActionSpanCalls($recorder);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]['attributes']['ixora.action.provider'])->toBe('home_assistant')
        ->and($spans[0]['attributes']['ixora.action.retry'])->toBe(false);
});

test('wrap() tags a retry attempt distinctly from a first attempt', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $telemetry->wrap(SmartHomeActionProvider::HomeAssistant, true, fn () => null, noopClassifyResult(), noopClassifyException());

    $spans = smartHomeActionSpanCalls($recorder);

    expect($spans[0]['attributes']['ixora.action.retry'])->toBe(true);
});

test('wrap() tags an unmapped/future provider slug distinctly from home_assistant', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $telemetry->wrap(SmartHomeActionProvider::Future, false, fn () => null, noopClassifyResult(), noopClassifyException());

    $spans = smartHomeActionSpanCalls($recorder);

    expect($spans[0]['attributes']['ixora.action.provider'])->toBe('future');
});

// 2. Outcome attribute sourced from the classifyResult callback.
test('wrap() sets ixora.action.outcome from the classifyResult callback on success', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $telemetry->wrap(
        SmartHomeActionProvider::HomeAssistant,
        false,
        fn () => 'the-action-result',
        fn ($result) => SmartHomeActionOutcome::Success,
        noopClassifyException(),
    );

    expect($recorder->mergedSpanAttributes()['ixora.action.outcome'])->toBe('success');
});

test('wrap() sets ixora.action.outcome to failure when classifyResult reports failure', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $telemetry->wrap(
        SmartHomeActionProvider::HomeAssistant,
        false,
        fn () => 'the-action-result',
        fn ($result) => SmartHomeActionOutcome::Failure,
        noopClassifyException(),
    );

    expect($recorder->mergedSpanAttributes()['ixora.action.outcome'])->toBe('failure');
});

// 3. Only the three allowed attributes are ever set — no forbidden fields.
test('wrap() never sets a forbidden attribute (no action/device/provider/user/schedule/vibe IDs, no payloads, no credentials)', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $telemetry->wrap(
        SmartHomeActionProvider::HomeAssistant,
        false,
        fn () => 'ignored',
        fn ($result) => SmartHomeActionOutcome::Success,
        noopClassifyException(),
    );

    $attributes = array_merge($recorder->startSpanCalls[0]['attributes'], $recorder->mergedSpanAttributes());

    expect(array_keys($attributes))->toEqualCanonicalizing([
        'ixora.action.provider',
        'ixora.action.retry',
        'ixora.action.outcome',
    ]);

    $forbidden = [
        'action_id', 'device_id', 'entity_id', 'provider_device_id', 'schedule_id',
        'vibe_id', 'user_id', 'trace_id', 'span_id', 'url', 'token', 'credential',
        'payload', 'header', 'body', 'json',
    ];

    foreach (array_keys($attributes) as $key) {
        foreach ($forbidden as $needle) {
            expect(str_contains($key, $needle))->toBeFalse("Attribute key [{$key}] must not contain forbidden fragment [{$needle}].");
        }
    }
});

// 4. Span lifetime — ends exactly once, and only after execute() returns.
test('wrap() ends the span exactly once after a successful execution', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $telemetry->wrap(SmartHomeActionProvider::HomeAssistant, false, fn () => null, noopClassifyResult(), noopClassifyException());

    expect($recorder->spanEndCalls)->toBe(1);
});

test('wrap() runs execute() strictly before ending the span — proving provider execution is fully contained inside the span', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $spanWasStillOpenDuringExecute = null;

    $telemetry->wrap(
        SmartHomeActionProvider::HomeAssistant,
        false,
        function () use ($recorder, &$spanWasStillOpenDuringExecute) {
            $spanWasStillOpenDuringExecute = $recorder->spanEndCalls === 0;

            return 'provider-call-happens-here';
        },
        noopClassifyResult(),
        noopClassifyException(),
    );

    expect($spanWasStillOpenDuringExecute)->toBeTrue()
        ->and($recorder->spanEndCalls)->toBe(1);
});

// 5. Exactly one span per call — no duplicates even across repeated calls.
test('wrap() never creates more than one span per call, and multiple calls create separate, non-duplicated spans', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $telemetry->wrap(SmartHomeActionProvider::HomeAssistant, false, fn () => null, noopClassifyResult(), noopClassifyException());
    $telemetry->wrap(SmartHomeActionProvider::HomeAssistant, true, fn () => null, noopClassifyResult(), noopClassifyException());

    $spans = smartHomeActionSpanCalls($recorder);

    expect($spans)->toHaveCount(2)
        ->and($recorder->spanEndCalls)->toBe(2);
});

// 6. Parent reuse — startSpan() is used (never a disconnected/duplicate root);
// Tracer::activeSpan() is never called, so this class nests a child under
// whatever is active (expected: the Queue Consumer span) rather than
// replacing it.
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

                throw new RuntimeException('activeSpan() must never be called by SmartHomeActionTelemetry');
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
    app()->forgetInstance(SmartHomeActionTelemetry::class);

    $telemetry = app(SmartHomeActionTelemetry::class);
    $telemetry->wrap(SmartHomeActionProvider::HomeAssistant, false, fn () => null, noopClassifyResult(), noopClassifyException());

    expect($activeSpanCalls)->toBe(0);
});

// 7. Failure path — exception is recorded, span still ends, and the
// exception always propagates unchanged (business/retry behavior preserved).
test('wrap() records the exception, marks the span as errored, ends it, and rethrows unchanged', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $exception = new RuntimeException('provider adapter blew up for a real business reason');

    expect(fn () => $telemetry->wrap(
        SmartHomeActionProvider::HomeAssistant,
        false,
        function () use ($exception) {
            throw $exception;
        },
        noopClassifyResult(),
        fn (Throwable $e) => SmartHomeActionOutcome::Failure,
    ))->toThrow(RuntimeException::class, 'provider adapter blew up for a real business reason');

    expect($recorder->spanExceptions)->toHaveCount(1)
        ->and($recorder->spanExceptions[0])->toBe($exception)
        ->and($recorder->spanErrorCalls)->toBe(1)
        ->and($recorder->spanEndCalls)->toBe(1)
        ->and($recorder->mergedSpanAttributes()['ixora.action.outcome'])->toBe('failure');
});

test('wrap() classifies an UnsupportedSmartHomeActionException-like failure as unsupported via the caller-supplied classifier, without this class importing that exception', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $exception = new InvalidArgumentException('Unsupported smart home action [explode].');

    expect(fn () => $telemetry->wrap(
        SmartHomeActionProvider::HomeAssistant,
        false,
        function () use ($exception) {
            throw $exception;
        },
        noopClassifyResult(),
        fn (Throwable $e) => $e instanceof InvalidArgumentException
            ? SmartHomeActionOutcome::Unsupported
            : SmartHomeActionOutcome::Failure,
    ))->toThrow(InvalidArgumentException::class);

    expect($recorder->mergedSpanAttributes()['ixora.action.outcome'])->toBe('unsupported');
});

// 7b. Business Failure Semantics (Phase 7B.4.5) — an `unsupported` outcome
// records the exception for context but never marks the span as errored:
// it is a recognized business decision (HTTP-4xx analogue), not an
// operation failure. Every other classified outcome still errors the span.
test('wrap() records the exception but does NOT mark the span as errored when the classifier reports an unsupported outcome', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $exception = new InvalidArgumentException('Unsupported smart home action [explode].');

    expect(fn () => $telemetry->wrap(
        SmartHomeActionProvider::HomeAssistant,
        false,
        function () use ($exception) {
            throw $exception;
        },
        noopClassifyResult(),
        fn (Throwable $e) => SmartHomeActionOutcome::Unsupported,
    ))->toThrow(InvalidArgumentException::class);

    expect($recorder->spanExceptions)->toHaveCount(1)
        ->and($recorder->spanExceptions[0])->toBe($exception)
        ->and($recorder->spanErrorCalls)->toBe(0)
        ->and($recorder->spanEndCalls)->toBe(1)
        ->and($recorder->mergedSpanAttributes()['ixora.action.outcome'])->toBe('unsupported');
});

test('wrap() still marks the span as errored when the classifier reports failure or the fail-open unknown outcome', function () {
    foreach ([
        fn (Throwable $e) => SmartHomeActionOutcome::Failure,
        function (Throwable $e): SmartHomeActionOutcome {
            throw new RuntimeException('classifier exploded — degrades to unknown');
        },
    ] as $classifyException) {
        $recorder = fakeSmartHomeActionTelemetry();
        $telemetry = app(SmartHomeActionTelemetry::class);

        expect(fn () => $telemetry->wrap(
            SmartHomeActionProvider::HomeAssistant,
            false,
            function () {
                throw new RuntimeException('boom');
            },
            noopClassifyResult(),
            $classifyException,
        ))->toThrow(RuntimeException::class, 'boom');

        expect($recorder->spanErrorCalls)->toBe(1);
    }
});

test('wrap() never calls classifyResult when execute() throws', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $classifyResultCalled = false;

    try {
        $telemetry->wrap(
            SmartHomeActionProvider::HomeAssistant,
            false,
            function () {
                throw new RuntimeException('boom');
            },
            function ($result) use (&$classifyResultCalled) {
                $classifyResultCalled = true;

                return SmartHomeActionOutcome::Success;
            },
            noopClassifyException(),
        );
    } catch (RuntimeException) {
        // Expected — see previous tests.
    }

    expect($classifyResultCalled)->toBeFalse();
});

// 8. Fail-open — a broken Tracer must never affect execute() or its result,
// nor a broken classifier callback.
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
    app()->forgetInstance(SmartHomeActionTelemetry::class);

    $telemetry = app(SmartHomeActionTelemetry::class);

    $result = null;
    expect(function () use ($telemetry, &$result) {
        $result = $telemetry->wrap(
            SmartHomeActionProvider::HomeAssistant,
            false,
            fn () => 'the-real-provider-result',
            noopClassifyResult(),
            noopClassifyException(),
        );
    })->not->toThrow(Throwable::class);

    expect($result)->toBe('the-real-provider-result');
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
    app()->forgetInstance(SmartHomeActionTelemetry::class);

    $telemetry = app(SmartHomeActionTelemetry::class);

    expect(fn () => $telemetry->wrap(
        SmartHomeActionProvider::HomeAssistant,
        false,
        function () {
            throw new DomainException('the real provider execution failure');
        },
        noopClassifyResult(),
        noopClassifyException(),
    ))->toThrow(DomainException::class, 'the real provider execution failure');
});

test('a classifyResult callback that throws never affects the returned result — outcome degrades to unknown', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $result = $telemetry->wrap(
        SmartHomeActionProvider::HomeAssistant,
        false,
        fn () => 'the-real-result',
        function ($result) {
            throw new RuntimeException('classifier exploded');
        },
        noopClassifyException(),
    );

    expect($result)->toBe('the-real-result')
        ->and($recorder->mergedSpanAttributes()['ixora.action.outcome'])->toBe('unknown')
        ->and($recorder->spanEndCalls)->toBe(1);
});

// 9. No metrics are ever recorded by this module.
test('wrap() never records a counter, histogram, or up-down counter', function () {
    $recorder = fakeSmartHomeActionTelemetry();
    $telemetry = app(SmartHomeActionTelemetry::class);

    $telemetry->wrap(SmartHomeActionProvider::HomeAssistant, false, fn () => null, noopClassifyResult(), noopClassifyException());

    expect($recorder->counterCalls)->toBe([])
        ->and($recorder->histogramCalls)->toBe([])
        ->and($recorder->upDownCounterCalls)->toBe([]);
});

// 10. Provider slug normalization — the Telemetry-layer enum, not the domain one.
test('SmartHomeActionProvider::fromProviderSlug maps the known slug and normalizes any unknown slug to Future', function () {
    expect(SmartHomeActionProvider::fromProviderSlug('home_assistant'))->toBe(SmartHomeActionProvider::HomeAssistant)
        ->and(SmartHomeActionProvider::fromProviderSlug('tuya'))->toBe(SmartHomeActionProvider::Future)
        ->and(SmartHomeActionProvider::fromProviderSlug('philips_hue'))->toBe(SmartHomeActionProvider::Future)
        ->and(SmartHomeActionProvider::fromProviderSlug('anything-unrecognized'))->toBe(SmartHomeActionProvider::Future);
});
