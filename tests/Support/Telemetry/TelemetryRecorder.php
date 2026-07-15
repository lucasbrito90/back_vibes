<?php

declare(strict_types=1);

namespace Tests\Support\Telemetry;

/**
 * Shared in-memory sink for the Recording* fakes below — lets Phase 7B.1
 * HTTP telemetry tests assert exactly what was recorded (counter/histogram
 * calls, active-span attribute calls) without a real OpenTelemetry SDK,
 * Collector, Prometheus, or Tempo (Part 8 boundary).
 */
final class TelemetryRecorder
{
    /** @var list<array{name: string, amount: int|float, attributes: array<string, mixed>}> */
    public array $counterCalls = [];

    /** @var list<array{name: string, value: int|float, attributes: array<string, mixed>}> */
    public array $histogramCalls = [];

    /** @var list<array<string, mixed>> */
    public array $spanAttributeCalls = [];

    /** @var list<\Throwable> */
    public array $spanExceptions = [];

    public int $spanErrorCalls = 0;

    public int $spanEndCalls = 0;

    public function recordCounterAdd(string $name, int|float $amount, array $attributes): void
    {
        $this->counterCalls[] = ['name' => $name, 'amount' => $amount, 'attributes' => $attributes];
    }

    public function recordHistogram(string $name, int|float $value, array $attributes): void
    {
        $this->histogramCalls[] = ['name' => $name, 'value' => $value, 'attributes' => $attributes];
    }

    public function recordSpanAttributes(array $attributes): void
    {
        $this->spanAttributeCalls[] = $attributes;
    }

    /**
     * All attributes ever set on the active span, merged in call order.
     *
     * @return array<string, mixed>
     */
    public function mergedSpanAttributes(): array
    {
        return array_merge(...($this->spanAttributeCalls === [] ? [[]] : $this->spanAttributeCalls));
    }
}
