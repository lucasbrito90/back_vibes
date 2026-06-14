<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Long-running scheduler worker loop for Scheduler MVP (Phase 7 — infra strategy).
 *
 * Runs indefinitely as an App Platform **worker** component, calling
 * `schedules:dispatch-due` approximately every --interval seconds.
 * Replaces the DO App Platform Scheduled Job approach, which was limited by:
 *   - Provider (digitalocean/digitalocean v2.87.0) not supporting SCHEDULED kind / cron_expression.
 *   - DO App Platform enforcing a 15-minute minimum cadence for scheduled jobs.
 *
 * The loop itself does not re-check idempotency — that guarantee lives in
 * `schedules:dispatch-due` (occurrence_key unique index — ADR-010). Rapid or
 * overlapping ticks are therefore safe: the inner command simply produces
 * a skipped_duplicate outcome rather than a duplicate execution row.
 *
 * Signal handling:
 *   SIGTERM / SIGINT → sets $shouldStop = true; the loop exits after the
 *   current tick completes (no mid-tick kill). Safe for App Platform graceful
 *   shutdown (DO sends SIGTERM before SIGKILL).
 *
 * App Platform worker lifecycle:
 *   - This command should be the run_command for the `scheduler` worker component.
 *   - A non-zero exit code will trigger an App Platform component restart.
 *   - The loop intentionally exits with SUCCESS on clean shutdown and FAILURE on
 *     unrecoverable panic, so the platform can distinguish the two.
 */
final class DispatchSchedulesLoopCommand extends Command
{
    protected $signature = 'schedules:dispatch-loop
                            {--interval=60 : Seconds to sleep between dispatch-due invocations}
                            {--once : Run a single iteration then exit (for manual testing)}
                            {--max-iterations= : Run exactly N iterations then exit (for tests)}';

    protected $description = 'Long-running worker loop: calls schedules:dispatch-due every --interval seconds.';

    private bool $shouldStop = false;

    public function handle(): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $once = (bool) $this->option('once');
        $maxIterations = $this->option('max-iterations') !== null
            ? max(1, (int) $this->option('max-iterations'))
            : null;

        $this->registerSignalHandlers();

        $this->line('[schedules:dispatch-loop] starting — interval='.$interval.'s'.($once ? ' once=true' : '').($maxIterations !== null ? " max-iterations={$maxIterations}" : ''));

        $iteration = 0;

        while (! $this->shouldStop) {
            $iteration++;
            $tickStart = CarbonImmutable::now('UTC');

            $this->runDispatch($tickStart, $iteration);

            if ($once || ($maxIterations !== null && $iteration >= $maxIterations)) {
                $this->shouldStop = true;
                break;
            }

            $this->sleepWithSignalCheck($interval, $tickStart);
        }

        $this->line('[schedules:dispatch-loop] stopped cleanly after '.$iteration.' iteration(s).');

        return self::SUCCESS;
    }

    /**
     * Call schedules:dispatch-due via the Artisan facade and surface the result.
     */
    private function runDispatch(CarbonImmutable $tickStart, int $iteration): void
    {
        $label = "[schedules:dispatch-loop] tick #{$iteration} @ {$tickStart->toIso8601String()}";

        try {
            $exitCode = $this->callSilently('schedules:dispatch-due');

            $status = $exitCode === self::SUCCESS ? 'ok' : "exit={$exitCode}";
            $this->line("{$label} — {$status}");
        } catch (Throwable $e) {
            $this->warn("{$label} — dispatch-due threw: {$e->getMessage()}");
        }
    }

    /**
     * Sleep for $interval seconds, waking periodically to honour signal checks.
     * Uses short sub-second sleeps so signal delivery is responsive without
     * burning CPU.
     */
    private function sleepWithSignalCheck(int $interval, CarbonImmutable $tickStart): void
    {
        $wakeAt = $tickStart->addSeconds($interval);

        while (! $this->shouldStop) {
            $remaining = CarbonImmutable::now('UTC')->diffInSeconds($wakeAt, absolute: false);

            if ($remaining <= 0) {
                break;
            }

            // Sleep in 1-second slices; pcntl_signal_dispatch() is called per-slice.
            sleep(1);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Register POSIX signal handlers so SIGTERM / SIGINT trigger a clean exit
     * rather than killing the process mid-transaction.
     */
    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $stop = function (): void {
            $this->shouldStop = true;
            $this->line('[schedules:dispatch-loop] shutdown signal received — stopping after current tick.');
        };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
