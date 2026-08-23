<?php

use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Histogram;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\UpDownCounter;
use App\Telemetry\PushNotifications\PushNotificationTelemetry;
use Tests\Support\Telemetry\RecordingMeter;
use Tests\Support\Telemetry\TelemetryRecorder;

/**
 * Phase 7B.5 — Push Notifications Business Telemetry. Exercises
 * App\Telemetry\PushNotifications\PushNotificationTelemetry directly, the
 * same way SmartHomeActionTelemetryTest.php exercises SmartHomeActionTelemetry.
 * Real wiring into PushNotificationJob is covered by
 * tests/Feature/PushNotifications/PushNotificationJobTest.php.
 */
function fakePushNotificationTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Meter::class, fn () => new RecordingMeter($recorder));
    app()->forgetInstance(PushNotificationTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, amount: int|float, attributes: array<string, mixed>}>
 */
function pushDeliveryTotalCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->counterCalls,
        fn (array $call) => $call['name'] === 'ixora.push.delivery.total',
    ));
}

test('recordDelivery() records exactly one ixora.push.delivery.total increment of 1, labeled notification_type and outcome', function () {
    $recorder = fakePushNotificationTelemetry();
    $telemetry = app(PushNotificationTelemetry::class);

    $telemetry->recordDelivery('smart_home_action_failed', 'success');

    $calls = pushDeliveryTotalCalls($recorder);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['amount'])->toBe(1)
        ->and($calls[0]['attributes']['notification_type'])->toBe('smart_home_action_failed')
        ->and($calls[0]['attributes']['outcome'])->toBe('success');
});

test('recordDelivery() records outcome=failure distinctly from outcome=success', function () {
    $recorder = fakePushNotificationTelemetry();
    $telemetry = app(PushNotificationTelemetry::class);

    $telemetry->recordDelivery('schedule_execution_failed', 'failure');

    $calls = pushDeliveryTotalCalls($recorder);

    expect($calls[0]['attributes']['outcome'])->toBe('failure')
        ->and($calls[0]['attributes']['notification_type'])->toBe('schedule_execution_failed');
});

test('recordDelivery() increments once per call — repeated calls never merge or double-count', function () {
    $recorder = fakePushNotificationTelemetry();
    $telemetry = app(PushNotificationTelemetry::class);

    $telemetry->recordDelivery('account_security_notice', 'success');
    $telemetry->recordDelivery('account_security_notice', 'failure');
    $telemetry->recordDelivery('smart_home_provider_unreachable', 'success');

    expect(pushDeliveryTotalCalls($recorder))->toHaveCount(3);
});

test('the delivery metric label set is exactly {environment, service_name, notification_type, outcome} — no forbidden or unbounded label', function () {
    $recorder = fakePushNotificationTelemetry();
    $telemetry = app(PushNotificationTelemetry::class);

    $telemetry->recordDelivery('smart_home_action_failed', 'success');

    $attributes = pushDeliveryTotalCalls($recorder)[0]['attributes'];

    expect(array_keys($attributes))->toEqualCanonicalizing([
        'environment', 'service_name', 'notification_type', 'outcome',
    ]);

    $forbidden = ['user_id', 'device_id', 'vibe_id', 'schedule_id', 'provider_connection_id', 'trace_id', 'token', 'email'];

    foreach (array_keys($attributes) as $key) {
        foreach ($forbidden as $needle) {
            expect(str_contains($key, $needle))->toBeFalse("Attribute key [{$key}] must not contain forbidden fragment [{$needle}].");
        }
    }
});

test('a broken Counter (registration succeeds, add() throws) never prevents recordDelivery() from returning — metrics recording is fail-open', function () {
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
    app()->forgetInstance(PushNotificationTelemetry::class);

    $telemetry = app(PushNotificationTelemetry::class);

    expect(fn () => $telemetry->recordDelivery('smart_home_action_failed', 'success'))
        ->not->toThrow(Throwable::class);
});

test('a broken Meter (counter() itself throws at construction) propagates from container resolution — construction-time failures are not caught here, matching SmartHomeActionTelemetry precedent', function () {
    app()->bind(Meter::class, fn () => new class implements Meter
    {
        public function counter(string $name, string $unit = '', string $description = ''): Counter
        {
            throw new RuntimeException('meter exploded');
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
    app()->forgetInstance(PushNotificationTelemetry::class);

    expect(fn () => app(PushNotificationTelemetry::class))->toThrow(RuntimeException::class);
});
