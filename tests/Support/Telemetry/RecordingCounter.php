<?php

declare(strict_types=1);

namespace Tests\Support\Telemetry;

use App\Telemetry\Contracts\Counter;

final class RecordingCounter implements Counter
{
    public function __construct(
        private readonly TelemetryRecorder $recorder,
        private readonly string $name,
    ) {}

    public function add(int|float $amount, array $attributes = []): void
    {
        $this->recorder->recordCounterAdd($this->name, $amount, $attributes);
    }
}
