<?php

declare(strict_types=1);

namespace App\Telemetry\Noop;

use App\Telemetry\Contracts\UpDownCounter;

final class NoopUpDownCounter implements UpDownCounter
{
    public function add(int|float $amount, array $attributes = []): void {}
}
