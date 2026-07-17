<?php

declare(strict_types=1);

namespace Tests\Support\Telemetry;

use App\Telemetry\Contracts\UpDownCounter;

final class RecordingUpDownCounter implements UpDownCounter
{
    public function __construct(
        private readonly TelemetryRecorder $recorder,
        private readonly string $name,
    ) {}

    public function add(int|float $amount, array $attributes = []): void
    {
        $this->recorder->recordUpDownCounterAdd($this->name, $amount, $attributes);
    }
}
