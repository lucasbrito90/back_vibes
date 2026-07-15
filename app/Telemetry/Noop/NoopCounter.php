<?php

declare(strict_types=1);

namespace App\Telemetry\Noop;

use App\Telemetry\Contracts\Counter;

final class NoopCounter implements Counter
{
    public function add(int|float $amount, array $attributes = []): void {}
}
