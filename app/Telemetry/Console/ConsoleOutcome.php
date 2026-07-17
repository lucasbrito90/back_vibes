<?php

declare(strict_types=1);

namespace App\Telemetry\Console;

/**
 * Bounded classification of a single Artisan command execution's result,
 * used as the `outcome` label on ixora.console.command.* metrics and as
 * the `ixora.console.outcome` span attribute (backend-queue-console-
 * instrumentation.md §"Metrics"). Maps exit codes exactly as specified:
 * 0 → success, any other exit code (including one produced by an
 * uncaught exception, which Symfony always converts to a non-zero exit
 * code before CommandFinished fires) → failed.
 *
 * `Cancelled` is part of the bounded set for forward compatibility (mirrors
 * App\Telemetry\Http\HttpOutcome::Cancelled and App\Telemetry\Queue\
 * QueueOutcome::Cancelled) but is never produced by fromExitCode() today —
 * there is no dedicated, safe, cross-platform signal for "command
 * cancelled" distinct from a non-zero exit code.
 */
enum ConsoleOutcome: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public static function fromExitCode(int $exitCode): self
    {
        return $exitCode === 0 ? self::Success : self::Failed;
    }
}
