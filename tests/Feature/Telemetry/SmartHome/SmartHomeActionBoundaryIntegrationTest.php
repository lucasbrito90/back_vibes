<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SmartHomeActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\VibeDeviceAction;
use App\PushNotifications\Services\PushNotificationEvents;
use App\SmartHome\ProviderAdapterResolver;
use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\SmartHome\SmartHomeActionTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

uses(RefreshDatabase::class);

/**
 * Phase 7B.4.3 — proves the `smart_home.action` Business Span is really
 * wired into the real SmartHomeActionJob::handle(), not just the isolated
 * SmartHomeActionTelemetry unit exercised in SmartHomeActionTelemetryTest.php.
 *
 * Mirrors SmartHomeDispatchBoundaryIntegrationTest.php's structure for the
 * Dispatch boundary.
 */
const ACTION_BOUNDARY_HA_BASE = 'https://ha.boundary.test';

function fakeSmartHomeActionBoundaryTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->forgetInstance(SmartHomeActionTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, attributes: array<string, mixed>}>
 */
function actionBoundarySpanCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->startSpanCalls,
        fn (array $call) => $call['name'] === 'smart_home.action',
    ));
}

/**
 * @param  array<string, mixed>  $connOverrides
 * @param  array<string, mixed>  $deviceOverrides
 * @param  array<string, mixed>  $actionOverrides
 */
function actionBoundaryAction(array $connOverrides = [], array $deviceOverrides = [], array $actionOverrides = []): VibeDeviceAction
{
    $connection = ProviderConnection::factory()->create(array_merge([
        'config' => ['base_url' => ACTION_BOUNDARY_HA_BASE],
    ], $connOverrides));

    $device = Device::factory()->create(array_merge([
        'provider_connection_id' => $connection->id,
        'user_id' => $connection->user_id,
        'provider' => $connection->provider,
        'provider_device_id' => 'light.boundary_test',
    ], $deviceOverrides));

    return VibeDeviceAction::factory()->create(array_merge([
        'device_id' => $device->id,
        'action_type' => 'turn_on',
        'parameters' => null,
    ], $actionOverrides));
}

function runActionBoundaryJob(VibeDeviceAction|int $action): void
{
    $id = $action instanceof VibeDeviceAction ? $action->id : $action;
    (new SmartHomeActionJob($id))->handle(
        app(ProviderAdapterResolver::class),
        app(PushNotificationEvents::class),
        app(SmartHomeActionTelemetry::class),
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Span creation on the real execution path
// ─────────────────────────────────────────────────────────────────────────────

test('a successful action execution creates exactly one smart_home.action span tagged home_assistant/success', function () {
    $recorder = fakeSmartHomeActionBoundaryTelemetry();
    Http::fake([ACTION_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $action = actionBoundaryAction();

    runActionBoundaryJob($action);

    $spans = actionBoundarySpanCalls($recorder);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]['attributes']['ixora.action.provider'])->toBe('home_assistant')
        ->and($spans[0]['attributes']['ixora.action.retry'])->toBe(false);

    // Phase 7B.4.4 nests one additional smart_home.provider span inside
    // this one (see SmartHomeProviderBoundaryIntegrationTest.php) — both
    // end exactly once each.
    expect($recorder->mergedSpanAttributes()['ixora.action.outcome'])->toBe('success')
        ->and($recorder->spanEndCalls)->toBe(2);
});

test('a failed provider ActionResult (HTTP 500) still creates one span tagged with outcome failure — no throw', function () {
    $recorder = fakeSmartHomeActionBoundaryTelemetry();
    Http::fake([ACTION_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 500)]);

    $action = actionBoundaryAction();

    expect(fn () => runActionBoundaryJob($action))->not->toThrow(Throwable::class);

    // Phase 7B.4.4 nests one additional smart_home.provider span inside
    // this one — a non-throwing failed ActionResult is not a span error on
    // either span (see SmartHomeProviderBoundaryIntegrationTest.php).
    expect(actionBoundarySpanCalls($recorder))->toHaveCount(1)
        ->and($recorder->mergedSpanAttributes()['ixora.action.outcome'])->toBe('failure')
        ->and($recorder->spanEndCalls)->toBe(2)
        ->and($recorder->spanExceptions)->toBe([]);
});

test('a provider connection failure (transport error) creates one span tagged with outcome failure — no throw', function () {
    $recorder = fakeSmartHomeActionBoundaryTelemetry();
    Http::fake(fn () => throw new ConnectionException('refused'));

    $action = actionBoundaryAction();

    expect(fn () => runActionBoundaryJob($action))->not->toThrow(Throwable::class);

    expect(actionBoundarySpanCalls($recorder))->toHaveCount(1)
        ->and($recorder->mergedSpanAttributes()['ixora.action.outcome'])->toBe('failure');
});

test('an unsupported action type creates one span tagged with outcome unsupported, records the exception, but does NOT mark the span as errored (Phase 7B.4.5 — business outcome, not a span error), and the job still does not throw', function () {
    $recorder = fakeSmartHomeActionBoundaryTelemetry();
    Http::fake();

    $action = actionBoundaryAction(actionOverrides: ['action_type' => 'explode']);

    expect(fn () => runActionBoundaryJob($action))->not->toThrow(Throwable::class);

    expect(actionBoundarySpanCalls($recorder))->toHaveCount(1)
        ->and($recorder->mergedSpanAttributes()['ixora.action.outcome'])->toBe('unsupported')
        ->and($recorder->spanEndCalls)->toBe(1)
        ->and($recorder->spanExceptions)->toHaveCount(1)
        ->and($recorder->spanErrorCalls)->toBe(0);

    Http::assertNothingSent();
});

test('an unexpected resolver error (unknown provider) creates one span tagged with outcome failure, records the exception, and the job still does not throw', function () {
    $recorder = fakeSmartHomeActionBoundaryTelemetry();
    Http::fake();

    $action = actionBoundaryAction(
        connOverrides: ['provider' => 'unknown_provider'],
        deviceOverrides: ['provider' => 'unknown_provider'],
    );

    expect(fn () => runActionBoundaryJob($action))->not->toThrow(Throwable::class);

    expect(actionBoundarySpanCalls($recorder))->toHaveCount(1)
        ->and($recorder->mergedSpanAttributes()['ixora.action.outcome'])->toBe('failure')
        ->and($recorder->spanExceptions)->toHaveCount(1)
        ->and($recorder->spanErrorCalls)->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Boundary isolation — guard-clause skips never create a span at all
// ─────────────────────────────────────────────────────────────────────────────

test('a missing/deleted action creates no smart_home.action span — the guard clause runs before the boundary begins', function () {
    $recorder = fakeSmartHomeActionBoundaryTelemetry();
    Http::fake();

    runActionBoundaryJob(999_999);

    expect(actionBoundarySpanCalls($recorder))->toBe([]);
    Http::assertNothingSent();
});

test('a deleted device (missing relation) creates no smart_home.action span', function () {
    $recorder = fakeSmartHomeActionBoundaryTelemetry();
    Http::fake();

    $action = actionBoundaryAction();
    $actionId = $action->id;
    $action->device->delete();

    runActionBoundaryJob($actionId);

    expect(actionBoundarySpanCalls($recorder))->toBe([]);
    Http::assertNothingSent();
});

// ─────────────────────────────────────────────────────────────────────────────
// Boundary isolation — the action span never includes provider HTTP time
// beyond what executeAction() itself performs (no double-counting, no
// additional network calls attributable to telemetry).
// ─────────────────────────────────────────────────────────────────────────────

test('the action span wraps exactly the single provider HTTP call — no extra calls, no duplicate spans', function () {
    $recorder = fakeSmartHomeActionBoundaryTelemetry();
    Http::fake([ACTION_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    runActionBoundaryJob(actionBoundaryAction());

    // Phase 7B.4.4 nests one additional smart_home.provider span inside
    // this one, ending exactly once too.
    Http::assertSentCount(1);
    expect(actionBoundarySpanCalls($recorder))->toHaveCount(1)
        ->and($recorder->spanEndCalls)->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Retry attribute — reflects Laravel's own attempt counter, not a custom one
// ─────────────────────────────────────────────────────────────────────────────

test('a first attempt (no underlying queue job) is tagged ixora.action.retry = false', function () {
    $recorder = fakeSmartHomeActionBoundaryTelemetry();
    Http::fake([ACTION_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    runActionBoundaryJob(actionBoundaryAction());

    $spans = actionBoundarySpanCalls($recorder);

    expect($spans[0]['attributes']['ixora.action.retry'])->toBe(false);
});

test('an attempt reported by the underlying queue job as > 1 is tagged ixora.action.retry = true', function () {
    $recorder = fakeSmartHomeActionBoundaryTelemetry();
    Http::fake([ACTION_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $action = actionBoundaryAction();

    $job = new SmartHomeActionJob($action->id);
    $job->job = new class
    {
        public function attempts(): int
        {
            return 2;
        }
    };

    $job->handle(
        app(ProviderAdapterResolver::class),
        app(PushNotificationEvents::class),
        app(SmartHomeActionTelemetry::class),
    );

    $spans = actionBoundarySpanCalls($recorder);

    expect($spans[0]['attributes']['ixora.action.retry'])->toBe(true);
});

// ─────────────────────────────────────────────────────────────────────────────
// Fail-open — a broken Tracer never affects the job's business behavior
// ─────────────────────────────────────────────────────────────────────────────

test('a broken Tracer never prevents the job from executing the action or notifying on failure', function () {
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

    Http::fake([ACTION_BOUNDARY_HA_BASE.'/api/services/*' => Http::response([], 200)]);
    Bus::fake();

    $action = actionBoundaryAction();

    expect(fn () => runActionBoundaryJob($action))->not->toThrow(Throwable::class);

    Http::assertSentCount(1);
});
