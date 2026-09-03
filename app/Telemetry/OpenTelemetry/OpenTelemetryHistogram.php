<?php

declare(strict_types=1);

namespace App\Telemetry\OpenTelemetry;

use App\Telemetry\Contracts\Histogram;
use OpenTelemetry\API\Metrics\HistogramInterface;

final class OpenTelemetryHistogram implements Histogram
{
    public function __construct(private readonly HistogramInterface $histogram) {}

    public function record(int|float $value, array $attributes = []): void
    {
        $this->histogram->record($value, $attributes);
    }
}
