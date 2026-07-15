<?php

declare(strict_types=1);

namespace App\Telemetry\Contracts;

/**
 * Monotonically increasing instrument (telemetry-naming-convention.md §5).
 * Use for counts that only go up — requests served, jobs failed, deliveries
 * attempted. Never decreases; use UpDownCounter for values that can shrink.
 */
interface Counter
{
    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function add(int|float $amount, array $attributes = []): void;
}
