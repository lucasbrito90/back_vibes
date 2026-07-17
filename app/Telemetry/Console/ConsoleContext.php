<?php

declare(strict_types=1);

namespace App\Telemetry\Console;

/**
 * Normalized, bounded snapshot of the command currently (or most recently)
 * executing — captured at CommandStarting time and read by both
 * ConsoleCommandTelemetry (metrics/span enrichment) and
 * App\Telemetry\Logging\ConsoleErrorContextLogTap (safe error-log context).
 *
 * $exitCode/$outcome start null (unknown until the command finishes) and
 * are filled in via withResult() once CommandFinished has computed them —
 * by the time Illuminate\Foundation\Console\Kernel::handle() logs an
 * uncaught command exception, CommandFinished (and therefore withResult())
 * has already run, so the log tap sees the real outcome, not a guess.
 *
 * Deliberately holds no argument values, option values, or secrets — see
 * backend-queue-console-instrumentation.md §"Cardinality safety".
 */
final class ConsoleContext
{
    public function __construct(
        public readonly string $command,
        public readonly ?int $exitCode = null,
        public readonly ?ConsoleOutcome $outcome = null,
    ) {}

    public function withResult(int $exitCode, ConsoleOutcome $outcome): self
    {
        return new self($this->command, $exitCode, $outcome);
    }

    /**
     * @return array<string, int|string>
     */
    public function toLogContext(): array
    {
        $context = ['command' => $this->command];

        if ($this->exitCode !== null) {
            $context['exit_code'] = $this->exitCode;
        }

        if ($this->outcome !== null) {
            $context['outcome'] = $this->outcome->value;
        }

        return $context;
    }
}
