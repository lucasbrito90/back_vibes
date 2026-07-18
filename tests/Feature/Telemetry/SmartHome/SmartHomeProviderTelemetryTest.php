<?php

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\SmartHome\SmartHomeProviderDeviceDomain;
use App\Telemetry\SmartHome\SmartHomeProviderTelemetry;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

/**
 * Phase 7B.4.4 — Smart Home Provider Execution boundary. Exercises
 * App\Telemetry\SmartHome\SmartHomeProviderTelemetry directly (the same way
 * SmartHomeActionTelemetryTest.php exercises SmartHomeActionTelemetry),
 * plus the real wiring into HomeAssistantAdapter::executeAction() covered
 * by the existing tests/Unit/SmartHome/HomeAssistantAdapterTest.php and the
 * dedicated boundary-integration test in
 * SmartHomeProviderBoundaryIntegrationTest.php.
 *
 * See backend-smart-home-provider-boundary.md for the full spec this test
 * file validates against.
 */
function fakeSmartHomeProviderTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->forgetInstance(SmartHomeProviderTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, attributes: array<string, mixed>}>
 */
function smartHomeProviderSpanCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->startSpanCalls,
        fn (array $call) => $call['name'] === 'smart_home.provider',
    ));
}

// 1. Span creation, naming, and the device_domain attribute.
test('wrap() creates exactly one smart_home.provider span tagged with the given device domain', function () {
    $recorder = fakeSmartHomeProviderTelemetry();
    $telemetry = app(SmartHomeProviderTelemetry::class);

    $telemetry->wrap('light', fn () => 'provider-result');

    $spans = smartHomeProviderSpanCalls($recorder);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]['attributes']['ixora.provider.device_domain'])->toBe('light');
});

test('wrap() normalizes an unrecognized domain slug to other', function () {
    $recorder = fakeSmartHomeProviderTelemetry();
    $telemetry = app(SmartHomeProviderTelemetry::class);

    $telemetry->wrap('vacuum', fn () => null);

    $spans = smartHomeProviderSpanCalls($recorder);

    expect($spans[0]['attributes']['ixora.provider.device_domain'])->toBe('other');
});

test('wrap() tags each of the four known actionable domains distinctly', function () {
    $recorder = fakeSmartHomeProviderTelemetry();
    $telemetry = app(SmartHomeProviderTelemetry::class);

    foreach (['light', 'switch', 'media_player', 'fan'] as $domain) {
        $telemetry->wrap($domain, fn () => null);
    }

    $spans = smartHomeProviderSpanCalls($recorder);

    expect(array_column($spans, 'attributes'))->toEqualCanonicalizing([
        ['ixora.provider.device_domain' => 'light'],
        ['ixora.provider.device_domain' => 'switch'],
        ['ixora.provider.device_domain' => 'media_player'],
        ['ixora.provider.device_domain' => 'fan'],
    ]);
});

// 2. Only the one allowed attribute is ever set — no forbidden fields, no
// url/method/status/duration (already owned by opentelemetry-auto-guzzle),
// no outcome/provider duplicate of smart_home.action's own attributes.
test('wrap() never sets a forbidden or duplicated attribute', function () {
    $recorder = fakeSmartHomeProviderTelemetry();
    $telemetry = app(SmartHomeProviderTelemetry::class);

    $telemetry->wrap('light', fn () => 'ignored');

    $attributes = array_merge($recorder->startSpanCalls[0]['attributes'], $recorder->mergedSpanAttributes());

    expect(array_keys($attributes))->toEqualCanonicalizing(['ixora.provider.device_domain']);

    $forbidden = [
        'action_id', 'device_id', 'entity_id', 'provider_device_id', 'schedule_id',
        'vibe_id', 'user_id', 'trace_id', 'span_id', 'url', 'token', 'credential',
        'authorization', 'payload', 'header', 'body', 'json', 'outcome',
        'retry', 'method', 'status_code', 'duration', 'address',
    ];

    foreach (array_keys($attributes) as $key) {
        // Note: "provider" itself is allowed as part of the
        // ixora.provider.* namespace prefix — what is forbidden is a
        // duplicate of smart_home.action's own ixora.action.provider
        // *value space* (checked separately: the only key here is
        // ixora.provider.device_domain, never ixora.provider.name).
        foreach ($forbidden as $needle) {
            expect(str_contains($key, $needle))->toBeFalse("Attribute key [{$key}] must not contain forbidden fragment [{$needle}].");
        }
    }

    expect(array_keys($attributes))->not->toContain('ixora.provider.name');
});

// 3. Span lifetime — ends exactly once, and only after execute() returns.
test('wrap() ends the span exactly once after a successful execution', function () {
    $recorder = fakeSmartHomeProviderTelemetry();
    $telemetry = app(SmartHomeProviderTelemetry::class);

    $telemetry->wrap('light', fn () => null);

    expect($recorder->spanEndCalls)->toBe(1);
});

test('wrap() runs execute() strictly before ending the span — proving the HTTP call is fully contained inside the span', function () {
    $recorder = fakeSmartHomeProviderTelemetry();
    $telemetry = app(SmartHomeProviderTelemetry::class);

    $spanWasStillOpenDuringExecute = null;

    $telemetry->wrap('light', function () use ($recorder, &$spanWasStillOpenDuringExecute) {
        $spanWasStillOpenDuringExecute = $recorder->spanEndCalls === 0;

        return 'http-call-happens-here';
    });

    expect($spanWasStillOpenDuringExecute)->toBeTrue()
        ->and($recorder->spanEndCalls)->toBe(1);
});

// 4. Exactly one span per call — no duplicates even across repeated calls.
test('wrap() never creates more than one span per call, and multiple calls create separate, non-duplicated spans', function () {
    $recorder = fakeSmartHomeProviderTelemetry();
    $telemetry = app(SmartHomeProviderTelemetry::class);

    $telemetry->wrap('light', fn () => null);
    $telemetry->wrap('switch', fn () => null);

    $spans = smartHomeProviderSpanCalls($recorder);

    expect($spans)->toHaveCount(2)
        ->and($recorder->spanEndCalls)->toBe(2);
});

// 5. Parent reuse — startSpan() is used (never a disconnected/duplicate root);
// Tracer::activeSpan() is never called, so this class nests a child under
// whatever is active (expected: smart_home.action) rather than replacing it.
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

                throw new RuntimeException('activeSpan() must never be called by SmartHomeProviderTelemetry');
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
    app()->forgetInstance(SmartHomeProviderTelemetry::class);

    $telemetry = app(SmartHomeProviderTelemetry::class);
    $telemetry->wrap('light', fn () => null);

    expect($activeSpanCalls)->toBe(0);
});

// 6. Failure path — an exception escaping the wrapped segment (e.g. a
// credential-decryption failure) is recorded, the span still ends, and the
// exception always propagates unchanged.
test('wrap() records the exception, marks the span as errored, ends it, and rethrows unchanged', function () {
    $recorder = fakeSmartHomeProviderTelemetry();
    $telemetry = app(SmartHomeProviderTelemetry::class);

    $exception = new RuntimeException('credential decryption blew up for a real reason');

    expect(fn () => $telemetry->wrap('light', function () use ($exception) {
        throw $exception;
    }))->toThrow(RuntimeException::class, 'credential decryption blew up for a real reason');

    expect($recorder->spanExceptions)->toHaveCount(1)
        ->and($recorder->spanExceptions[0])->toBe($exception)
        ->and($recorder->spanErrorCalls)->toBe(1)
        ->and($recorder->spanEndCalls)->toBe(1);
});

// A normally-returned failed ActionResult is NOT an exception and must
// never mark the span as an error — that failure is already fully visible
// one level deeper on the nested Guzzle CLIENT span.
test('wrap() does not mark the span as errored for a normally-returned failure value', function () {
    $recorder = fakeSmartHomeProviderTelemetry();
    $telemetry = app(SmartHomeProviderTelemetry::class);

    $telemetry->wrap('light', fn () => new class
    {
        public bool $success = false;
    });

    expect($recorder->spanErrorCalls)->toBe(0)
        ->and($recorder->spanExceptions)->toBe([])
        ->and($recorder->spanEndCalls)->toBe(1);
});

// 7. Fail-open — a broken Tracer must never affect execute() or its result.
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
    app()->forgetInstance(SmartHomeProviderTelemetry::class);

    $telemetry = app(SmartHomeProviderTelemetry::class);

    $result = null;
    expect(function () use ($telemetry, &$result) {
        $result = $telemetry->wrap('light', fn () => 'the-real-http-result');
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
    app()->forgetInstance(SmartHomeProviderTelemetry::class);

    $telemetry = app(SmartHomeProviderTelemetry::class);

    expect(fn () => $telemetry->wrap('light', function () {
        throw new DomainException('the real provider execution failure');
    }))->toThrow(DomainException::class, 'the real provider execution failure');
});

// 8. No metrics are ever recorded by this module.
test('wrap() never records a counter, histogram, or up-down counter', function () {
    $recorder = fakeSmartHomeProviderTelemetry();
    $telemetry = app(SmartHomeProviderTelemetry::class);

    $telemetry->wrap('light', fn () => null);

    expect($recorder->counterCalls)->toBe([])
        ->and($recorder->histogramCalls)->toBe([])
        ->and($recorder->upDownCounterCalls)->toBe([]);
});

// 9. Domain slug normalization — the Telemetry-layer enum, not a domain constant.
test('SmartHomeProviderDeviceDomain::fromDomainSlug maps the four known domains and normalizes any unknown slug to Other', function () {
    expect(SmartHomeProviderDeviceDomain::fromDomainSlug('light'))->toBe(SmartHomeProviderDeviceDomain::Light)
        ->and(SmartHomeProviderDeviceDomain::fromDomainSlug('switch'))->toBe(SmartHomeProviderDeviceDomain::Switch)
        ->and(SmartHomeProviderDeviceDomain::fromDomainSlug('media_player'))->toBe(SmartHomeProviderDeviceDomain::MediaPlayer)
        ->and(SmartHomeProviderDeviceDomain::fromDomainSlug('fan'))->toBe(SmartHomeProviderDeviceDomain::Fan)
        ->and(SmartHomeProviderDeviceDomain::fromDomainSlug('sensor'))->toBe(SmartHomeProviderDeviceDomain::Other)
        ->and(SmartHomeProviderDeviceDomain::fromDomainSlug('anything-unrecognized'))->toBe(SmartHomeProviderDeviceDomain::Other);
});
