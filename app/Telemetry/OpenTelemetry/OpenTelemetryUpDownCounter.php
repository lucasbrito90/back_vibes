<?php

declare(strict_types=1);

namespace App\Telemetry\OpenTelemetry;

use App\Telemetry\Contracts\UpDownCounter;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;

final class OpenTelemetryUpDownCounter implements UpDownCounter
{
    public function __construct(private readonly UpDownCounterInterface $upDownCounter) {}

    public function add(int|float $amount, array $attributes = []): void
    {
        $this->upDownCounter->add($amount, $attributes);
    }
}
