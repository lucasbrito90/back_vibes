<?php

declare(strict_types=1);

namespace App\Telemetry\Scheduler;

/**
 * Normalized, bounded snapshot of a single scheduled event execution —
 * built at ScheduledTaskStarting (or, for a generic skip, at
 * ScheduledTaskSkipped with no preceding start) and read by
 * SchedulerExecutionTelemetry (metrics/span enrichment) and
 * App\Telemetry\Logging\SchedulerErrorContextLogTap (safe error-log
 * context).
 *
 * $outcome/$exitCode start null (unknown until the event finishes) and are
 * filled in via withResult() once a terminal handler has computed them.
 *
 * Deliberately holds no Schedule model ID, Vibe ID, user/device ID, full
 * command line, argument/option values, or closure source — see backend-
 * generic-scheduler-instrumentation.md §"Cardinality safety". $expression
 * and $timezone are kept for span/log attributes only (Part 4) — neither
 * is ever added to a metric label (see SchedulerExecutionTelemetry).
 */
final class SchedulerContext
{
    public function __construct(
        public readonly string $eventName,
        public readonly SchedulerEventType $eventType,
        public readonly SchedulerExecutionMode $executionMode,
        public readonly ?string $expression = null,
        public readonly ?string $timezone = null,
        public readonly ?SchedulerOutcome $outcome = null,
        public readonly ?int $exitCode = null,
    ) {}

    public function withResult(SchedulerOutcome $outcome, ?int $exitCode = null): self
    {
        return new self(
            $this->eventName,
            $this->eventType,
            $this->executionMode,
            $this->expression,
            $this->timezone,
            $outcome,
            $exitCode,
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toLogContext(): array
    {
        $context = [
            'scheduler_event' => $this->eventName,
            'scheduler_event_type' => $this->eventType->value,
            'scheduler_execution_mode' => $this->executionMode->value,
        ];

        if ($this->outcome !== null) {
            $context['scheduler_outcome'] = $this->outcome->value;
        }

        if ($this->exitCode !== null) {
            $context['scheduler_exit_code'] = $this->exitCode;
        }

        if ($this->timezone !== null && $this->timezone !== '') {
            $context['scheduler_timezone'] = $this->timezone;
        }

        return $context;
    }
}
