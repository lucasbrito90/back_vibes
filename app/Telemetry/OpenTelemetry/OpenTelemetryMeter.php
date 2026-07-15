<?php

declare(strict_types=1);

namespace App\Telemetry\OpenTelemetry;

use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Histogram;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\UpDownCounter;
use OpenTelemetry\API\Metrics\MeterInterface;

/**
 * Wraps an OpenTelemetry SDK MeterInterface. Resolved lazily from
 * Globals::meterProvider() by OpenTelemetryManager.
 */
final class OpenTelemetryMeter implements Meter
{
    public function __construct(private readonly MeterInterface $meter) {}

    public function counter(string $name, string $unit = '', string $description = ''): Counter
    {
        return new OpenTelemetryCounter($this->meter->createCounter($name, $unit ?: null, $description ?: null));
    }

    public function histogram(string $name, string $unit = '', string $description = ''): Histogram
    {
        return new OpenTelemetryHistogram($this->meter->createHistogram($name, $unit ?: null, $description ?: null));
    }

    public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
    {
        return new OpenTelemetryUpDownCounter($this->meter->createUpDownCounter($name, $unit ?: null, $description ?: null));
    }
}
