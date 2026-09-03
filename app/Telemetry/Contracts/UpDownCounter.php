<?php

declare(strict_types=1);

namespace App\Telemetry\Contracts;

/**
 * Instrument whose value can increase or decrease (telemetry-naming-convention.md §5).
 * Use for queue depth, active connections, in-flight requests — never for
 * latency (prefer Histogram).
 */
interface UpDownCounter
{
    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function add(int|float $amount, array $attributes = []): void;
}
