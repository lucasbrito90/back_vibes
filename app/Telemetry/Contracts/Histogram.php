<?php

declare(strict_types=1);

namespace App\Telemetry\Contracts;

/**
 * Distribution instrument (telemetry-naming-convention.md §5). Default choice
 * for latency and size measurements — enables p50/p95/p99 in Prometheus.
 * Prefer over Gauge for anything resembling a duration.
 */
interface Histogram
{
    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function record(int|float $value, array $attributes = []): void;
}
