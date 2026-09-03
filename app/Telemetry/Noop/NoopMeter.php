<?php

declare(strict_types=1);

namespace App\Telemetry\Noop;

use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Histogram;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\UpDownCounter;

final class NoopMeter implements Meter
{
    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return new NoopCounter;
    }

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        return new NoopHistogram;
    }

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return new NoopUpDownCounter;
    }
}
