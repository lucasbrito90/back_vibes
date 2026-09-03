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

    /** @var list<array{name: string, amount: int|float, attributes: array<string, mixed>}> */
    public array $upDownCounterCalls = [];

    /** @var list<array<string, mixed>> */
    public array $spanAttributeCalls = [];

    /** @var list<\Throwable> */
    public array $spanExceptions = [];

    public int $spanErrorCalls = 0;

    public int $spanEndCalls = 0;

    /**
     * Every Tracer::startSpan() call — added in Phase 7B.3 (Scheduler), the
     * first module in this Telemetry Abstraction Layer permitted to create
     * a domain span rather than only enrich an existing one. Each started
     * span gets its own RecordingActiveSpan instance (see RecordingTracer),
     * whose end()/setAttributes()/setError()/recordException() calls are
     * still funneled into this same shared recorder — spanEndCalls,
     * spanErrorCalls, spanExceptions, and spanAttributeCalls therefore
     * reflect *every* span (active-span enrichment and started spans
     * alike), while startSpanCalls lets a test isolate exactly which
     * spans were newly created.
     *
     * @var list<array{name: string, attributes: array<string, mixed>}>
     */
    public array $startSpanCalls = [];

    public function recordStartSpan(string $name, array $attributes): void
    {
        $this->startSpanCalls[] = ['name' => $name, 'attributes' => $attributes];
    }

    public function recordCounterAdd(string $name, int|float $amount, array $attributes): void
    {
        $this->counterCalls[] = ['name' => $name, 'amount' => $amount, 'attributes' => $attributes];
    }

    public function recordHistogram(string $name, int|float $value, array $attributes): void
    {
        $this->histogramCalls[] = ['name' => $name, 'value' => $value, 'attributes' => $attributes];
    }

    public function recordUpDownCounterAdd(string $name, int|float $amount, array $attributes): void
    {
        $this->upDownCounterCalls[] = ['name' => $name, 'amount' => $amount, 'attributes' => $attributes];
    }

    /**
     * Net sum of every add() call recorded for a given UpDownCounter name —
     * the simplest possible proof that increments/decrements stayed in
     * balance (Part 11 scenario 8, long-running worker safety).
     */
    public function netUpDownCounter(string $name): int|float
    {
        return array_sum(array_map(
            fn (array $call) => $call['amount'],
            array_filter($this->upDownCounterCalls, fn (array $call) => $call['name'] === $name),
        ));
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
