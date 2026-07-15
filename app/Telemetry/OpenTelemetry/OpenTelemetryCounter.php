<?php

declare(strict_types=1);

namespace App\Telemetry\OpenTelemetry;

use App\Telemetry\Contracts\Counter;
use OpenTelemetry\API\Metrics\CounterInterface;

final class OpenTelemetryCounter implements Counter
{
    public function __construct(private readonly CounterInterface $counter) {}

    public function add(int|float $amount, array $attributes = []): void
    {
        $this->counter->add($amount, $attributes);
    }
}
