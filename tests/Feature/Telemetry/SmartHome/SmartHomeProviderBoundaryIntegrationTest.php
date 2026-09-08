<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SceneActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\SmartHome\ProviderAdapterResolver;
use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\SmartHome\SmartHomeActionTelemetry;
use App\Telemetry\SmartHome\SmartHomeProviderTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

uses(RefreshDatabase::class);

/**
 * Phase 7B.4.4 — proves the `smart_home.provider` Business Span is really
 * wired into the real HomeAssistantAdapter::executeAction(), reached via
 * the full SceneActionJob::handle() -> ProviderAdapterResolver ->
 * HomeAssistantAdapter pipeline — not just the isolated
 * SmartHomeProviderTelemetry unit exercised in
 * SmartHomeProviderTelemetryTest.php.
 *
 * Mirrors SmartHomeActionBoundaryIntegrationTest.php's structure for the
 * Action boundary.
 */
const PROVIDER_BOUNDARY_HA_BASE = 'https://ha.provider-boundary.test';

function fakeSmartHomeProviderBoundaryTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->forgetInstance(SmartHomeActionTelemetry::class);
    app()->forgetInstance(SmartHomeProviderTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, attributes: array<string, mixed>}>
 */
function spanCallsNamed(TelemetryRecorder $recorder, string $name): array
{
    return array_values(array_filter(
        $recorder->startSpanCalls,
        fn (array $call) => $call['name'] === $name,
    ));
}

/**
 * @param  array<string, mixed>  $connOverrides
 * @param  array<string, mixed>  $deviceOverrides
 * @param  array<string, mixed>  $actionOverrides
 */
function providerBoundaryAction(
    array $connOverrides = [],
    array $deviceOverrides = [],
    array $actionOverrides = [],
    string $providerDeviceId = 'light.provider_boundary_test',
): SceneAction {
    $connection = ProviderConnection::factory()->create(array_merge([
        'config' => ['base_url' => PROVIDER_BOUNDARY_HA_BASE],
    ], $connOverrides));

    $device = Device::factory()->create(array_merge([
        'provider_connection_id' => $connection->id,
        'user_id' => $connection->user_id,
        'provider' => $connection->provider,
        'provider_device_id' => $providerDeviceId,
    ], $deviceOverrides));

    $scene = Scene::factory()->create(['user_id' => $connection->user_id]);

    return SceneAction::factory()->create(array_merge([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'action_type' => 'turn_on',
        'parameters' => null,
    ], $actionOverrides));
}

function runProviderBoundaryJob(SceneAction|int $action): void
{
    $id = $action instanceof SceneAction ? $action->id : $action;
    app()->call([new SceneActionJob($id, (string) Str::uuid()), 'handle']);
}

// ─────────────────────────────────────────────────────────────────────────────
// Span creation, naming, parent/child hierarchy, and the device_domain attribute
// ─────────────────────────────────────────────────────────────────────────────

test('a successful action execution creates both smart_home.action and smart_home.provider spans, the latter tagged with the correct device domain', function () {
    $recorder = fakeSmartHomeProviderBoundaryTelemetry();
    Http::fake([PROVIDER_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    runProviderBoundaryJob(providerBoundaryAction(providerDeviceId: 'switch.provider_boundary_test'));

    $actionSpans = spanCallsNamed($recorder, 'smart_home.action');
    $providerSpans = spanCallsNamed($recorder, 'smart_home.provider');

    expect($actionSpans)->toHaveCount(1)
        ->and($providerSpans)->toHaveCount(1)
        ->and($providerSpans[0]['attributes']['ixora.provider.device_domain'])->toBe('switch');
});

test('the smart_home.provider span is started strictly after smart_home.action — proving it nests as a child, not a sibling or duplicate root', function () {
    $recorder = fakeSmartHomeProviderBoundaryTelemetry();
    Http::fake([PROVIDER_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    runProviderBoundaryJob(providerBoundaryAction());

    $names = array_column($recorder->startSpanCalls, 'name');
    $actionIndex = array_search('smart_home.action', $names, true);
    $providerIndex = array_search('smart_home.provider', $names, true);

    expect($actionIndex)->not->toBeFalse()
        ->and($providerIndex)->not->toBeFalse()
        ->and($providerIndex)->toBeGreaterThan($actionIndex);
});

test('both spans end exactly once each — no duplicate spans anywhere in the pipeline', function () {
    $recorder = fakeSmartHomeProviderBoundaryTelemetry();
    Http::fake([PROVIDER_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    runProviderBoundaryJob(providerBoundaryAction());

    expect($recorder->startSpanCalls)->toHaveCount(2)
        ->and($recorder->spanEndCalls)->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Boundary isolation — the unsupported-action check runs before the
// Provider Boundary begins, so no smart_home.provider span is ever created
// for it, even though smart_home.action still records the outcome.
// ─────────────────────────────────────────────────────────────────────────────

test('an unsupported action type creates a smart_home.action span but NO smart_home.provider span — no provider work ever begins', function () {
    $recorder = fakeSmartHomeProviderBoundaryTelemetry();
    Http::fake();

    $action = providerBoundaryAction(actionOverrides: ['action_type' => 'explode']);

    expect(fn () => runProviderBoundaryJob($action))->not->toThrow(Throwable::class);

    expect(spanCallsNamed($recorder, 'smart_home.action'))->toHaveCount(1)
        ->and(spanCallsNamed($recorder, 'smart_home.provider'))->toBe([]);

    Http::assertNothingSent();
});

test('an unknown provider (resolver rejects before any adapter is reached) creates no smart_home.provider span', function () {
    $recorder = fakeSmartHomeProviderBoundaryTelemetry();
    Http::fake();

    $action = providerBoundaryAction(
        connOverrides: ['provider' => 'unknown_provider'],
        deviceOverrides: ['provider' => 'unknown_provider'],
    );

    expect(fn () => runProviderBoundaryJob($action))->not->toThrow(Throwable::class);

    expect(spanCallsNamed($recorder, 'smart_home.action'))->toHaveCount(1)
        ->and(spanCallsNamed($recorder, 'smart_home.provider'))->toBe([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Failure model — a returned failed ActionResult (business-legitimate
// outcome, HTTP error status or transport exception caught internally by
// HomeAssistantAdapter) is NOT a span error; only an exception escaping the
// wrapped segment entirely would be.
// ─────────────────────────────────────────────────────────────────────────────

test('a failed provider ActionResult (HTTP 500) still creates a smart_home.provider span that is NOT marked as errored', function () {
    $recorder = fakeSmartHomeProviderBoundaryTelemetry();
    Http::fake([PROVIDER_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 500)]);

    runProviderBoundaryJob(providerBoundaryAction());

    expect(spanCallsNamed($recorder, 'smart_home.provider'))->toHaveCount(1)
        ->and($recorder->spanErrorCalls)->toBe(0)
        ->and($recorder->spanExceptions)->toBe([]);
});

test('a provider connection failure (transport exception, caught internally) still creates a smart_home.provider span that is NOT marked as errored', function () {
    $recorder = fakeSmartHomeProviderBoundaryTelemetry();
    Http::fake(fn () => throw new ConnectionException('refused'));

    runProviderBoundaryJob(providerBoundaryAction());

    expect(spanCallsNamed($recorder, 'smart_home.provider'))->toHaveCount(1)
        ->and($recorder->spanErrorCalls)->toBe(0)
        ->and($recorder->spanExceptions)->toBe([]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Boundary isolation — the provider span wraps exactly the single provider
// HTTP call, no more, no less; no extra network calls attributable to
// telemetry itself.
// ─────────────────────────────────────────────────────────────────────────────

test('the provider span wraps exactly the single provider HTTP call — no extra calls, no duplicate spans', function () {
    $recorder = fakeSmartHomeProviderBoundaryTelemetry();
    Http::fake([PROVIDER_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    runProviderBoundaryJob(providerBoundaryAction());

    Http::assertSentCount(1);
    expect(spanCallsNamed($recorder, 'smart_home.provider'))->toHaveCount(1)
        ->and($recorder->spanEndCalls)->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Security — the specific entity id / provider_device_id never leaks into
// any span attribute anywhere in the pipeline.
// ─────────────────────────────────────────────────────────────────────────────

test('no span attribute anywhere in the pipeline ever contains the specific provider_device_id/entity_id', function () {
    $recorder = fakeSmartHomeProviderBoundaryTelemetry();
    Http::fake([PROVIDER_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    runProviderBoundaryJob(providerBoundaryAction(providerDeviceId: 'light.super_secret_living_room'));

    $allAttributeValues = [
        ...array_merge(...array_map(fn (array $call) => array_values($call['attributes']), $recorder->startSpanCalls)),
        ...array_values($recorder->mergedSpanAttributes()),
    ];

    foreach ($allAttributeValues as $value) {
        if (is_string($value)) {
            expect($value)->not->toContain('super_secret_living_room');
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Fail-open — a broken Tracer never affects the job's business behavior,
// including the provider execution itself.
// ─────────────────────────────────────────────────────────────────────────────

test('a broken Tracer never prevents the full pipeline from executing the provider call and returning a result', function () {
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
    app()->forgetInstance(SmartHomeProviderTelemetry::class);

    Http::fake([PROVIDER_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $action = providerBoundaryAction();

    expect(fn () => runProviderBoundaryJob($action))->not->toThrow(Throwable::class);

    Http::assertSentCount(1);
});
