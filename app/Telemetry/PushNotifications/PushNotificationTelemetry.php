<?php

declare(strict_types=1);

namespace App\Telemetry\PushNotifications;

use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Meter;
use Throwable;

/**
 * Business Metrics for push delivery (Phase 7B.5 — observability-foundation
 * Phase 7B.5). Owns exactly one instrument:
 *
 * - `ixora.push.delivery.total` (Counter, unit `{delivery}`) — one increment
 *   per per-token delivery attempt made by
 *   App\Jobs\PushNotifications\PushNotificationJob, labeled `notification_type`
 *   (the ADR-019 event taxonomy value already carried on
 *   NotificationPayload::$data['type']) and `outcome` (success/failure).
 *
 * Per metrics-philosophy.md §11 and telemetry-naming-convention.md §5, this is
 * the platform's canonical name and label set for this metric — do not add a
 * `provider` label (unbounded-adjacent today, FCM-only; revisit only via a
 * naming-convention update) and do not rename in place.
 *
 * A job-level skip (user not found, no active tokens) is not a delivery
 * attempt and is therefore never counted here — mirrors the guard-clause
 * exclusion precedent SmartHomeActionTelemetry already established.
 *
 * Consumes only App\Telemetry\Contracts\{Meter,Counter} — no OpenTelemetry SDK
 * import, no App\Models\*, App\Jobs\*, or App\PushNotifications\* import.
 * recordDelivery() is fail-open: a broken Meter/Counter can never affect
 * PushNotificationJob's own delivery result (telemetry-availability-policy.md).
 */
final class PushNotificationTelemetry
{
    private const METRIC_DELIVERY_TOTAL = 'ixora.push.delivery.total';

    private readonly Counter $deliveryTotal;

    public function __construct(
        Meter $meter,
        private readonly string $environment,
        private readonly string $serviceName,
    ) {
        $this->deliveryTotal = $meter->counter(
            self::METRIC_DELIVERY_TOTAL,
            unit: '{delivery}',
            description: 'Total push delivery attempts, labeled by notification type and outcome.',
        );
    }

    public function recordDelivery(string $notificationType, string $outcome): void
    {
        $this->safely(fn () => $this->deliveryTotal->add(1, [
            'environment' => $this->environment,
            'service_name' => $this->serviceName,
            'notification_type' => $notificationType,
            'outcome' => $outcome,
        ]));
    }

    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            // Intentionally swallowed — telemetry must never affect push
            // delivery, token deactivation, or job retries
            // (telemetry-availability-policy.md).
        }
    }
}
