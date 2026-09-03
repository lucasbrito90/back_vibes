<?php

declare(strict_types=1);

namespace App\Telemetry\Noop;

use App\Telemetry\Contracts\Histogram;

final class NoopHistogram implements Histogram
{
    public function record(int|float $value, array $attributes = []): void {}
}
