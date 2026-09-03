<?php

declare(strict_types=1);

namespace Tests\Support\Telemetry;

use App\Telemetry\Contracts\Histogram;

final class RecordingHistogram implements Histogram
{
    public function __construct(
        private readonly TelemetryRecorder $recorder,
        private readonly string $name,
    ) {}

    public function record(int|float $value, array $attributes = []): void
    {
        $this->recorder->recordHistogram($this->name, $value, $attributes);
    }
}
