<?php

declare(strict_types=1);

namespace App\Telemetry\Contracts;

/**
 * Vendor-neutral meter contract for aggregated measurements
 * (metrics-philosophy.md). Domain code depends on this interface — or on
 * TelemetryManager::meter() — and never on the OpenTelemetry SDK directly.
 *
 * Phase 7A wires the underlying provider but records no product metrics.
 * Phase 7B is the first phase permitted to record metrics through this
 * contract, following metrics-philosophy.md and telemetry-naming-convention.md
 * §5 (the `ixora.` namespace and instrument-type rules).
 */
interface Meter
{
    public function counter(string $name, string $unit = '', string $description = ''): Counter;

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram;

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter;
}
