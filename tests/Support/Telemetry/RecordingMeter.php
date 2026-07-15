<?php

declare(strict_types=1);

namespace Tests\Support\Telemetry;

use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Histogram;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\UpDownCounter;
use App\Telemetry\Noop\NoopUpDownCounter;

final class RecordingMeter implements Meter
{
    public function __construct(private readonly TelemetryRecorder $recorder) {}

    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return new RecordingCounter($this->recorder, $name);
    }

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        return new RecordingHistogram($this->recorder, $name);
    }

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return new NoopUpDownCounter;
    }
}
