<?php

declare(strict_types=1);

namespace App\Telemetry\Console;

use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Histogram;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Tracer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Throwable;

/**
 * The Ixora-specific layer on top of the per-command span
 * opentelemetry-auto-laravel already starts around
 * Illuminate\Console\Command::execute() (backend-queue-console-
 * instrumentation.md §"Console auto-instrumentation review"). Records the
 * two minimum console metrics (Part 5) and exposes the most recently
 * started command's normalized context to ConsoleErrorContextLogTap —
 * never creates a span, never alters command output or exit code, never
 * throws.
 *
 * Lifecycle integration point (Part 7): Illuminate\Console\Events\
 * CommandStarting / CommandFinished — the Laravel events the spec prefers.
 * Documented, verified limitation (backend-queue-console-instrumentation.md
 * §"Console lifecycle integration"): Laravel only wires these events when
 * `! app()->runningUnitTests()` (Illuminate\Foundation\Console\Kernel::
 * rerouteSymfonyCommandEvents(), called from the app's `booted()` hook).
 * back_vibes's test suite runs with APP_ENV=testing, so these events never
 * fire through a real command execution inside this repository's own
 * automated tests — verified empirically, not assumed. They fire correctly
 * for every real console invocation outside testing: `php artisan …`,
 * `Artisan::call(...)`, and this application's own `queue:work` /
 * `schedule:run` processes. This class is therefore tested directly
 * (unit-level) and via explicit Event::dispatch() of these two events
 * (the standard Laravel pattern for testing event listeners) rather than
 * through `$this->artisan()`/`Artisan::call()` inside a Pest test.
 *
 * A second, related limitation: CommandStarting fires before
 * Command::execute() starts (so before the per-command span exists) and
 * CommandFinished fires after it has already ended — enrichment via
 * Tracer::activeSpan() from either listener can therefore only ever reach
 * a *coarser* already-active span (e.g. the "Artisan handler" span
 * Illuminate\Contracts\Console\Kernel::handle() is wrapped in, present only
 * for a real top-level `php artisan …` CLI invocation) — it safely no-ops
 * everywhere else via the same Noop-safe activeSpan() contract. Metrics and
 * log context are unaffected by this — see the doc's §"Span enrichment".
 *
 * Nested commands (Part 8): `$this->call()`/`$this->callSilent()` inside a
 * command's handle() never dispatch ConsoleEvents::COMMAND/TERMINATE at all
 * (Illuminate\Console\Concerns\CallsCommands::runCommand() calls
 * `$command->run()` directly) — so this class only ever observes the
 * top-level command Laravel/Symfony itself is running, exactly once,
 * regardless of how many commands it calls internally. There is therefore
 * only ever at most one command "in flight" as far as this class is
 * concerned — no stack is needed (contrast with QueueExecutionTelemetry,
 * where a job can synchronously dispatch another job).
 *
 * Log correlation (Part 9): the current command's context is intentionally
 * *not* cleared when CommandFinished records it — Illuminate\Foundation\
 * Console\Kernel::handle()'s own exception log (the "existing failure log"
 * for a real top-level CLI invocation) happens strictly after
 * CommandFinished (Symfony dispatches ConsoleEvents::TERMINATE before
 * re-throwing). The `$finishedForCurrent` flag guards metrics against being
 * recorded twice for the same command, independent of the context staying
 * readable for the log tap. The stale-context window this leaves open is
 * bounded to "until the next command starts" — realistic console processes
 * run exactly one top-level command before exiting, so this cannot
 * accumulate the way an ambient "current job" would in a long-running
 * queue worker.
 *
 * Consumes only App\Telemetry\Contracts\{Tracer,Meter,Counter,Histogram} —
 * no OpenTelemetry SDK import anywhere in this class or elsewhere in
 * app/Telemetry/Console.
 */
final class ConsoleCommandTelemetry
{
    private const METRIC_COMMAND_TOTAL = 'ixora.console.command.total';

    private const METRIC_DURATION = 'ixora.console.command.duration';

    /** Matches ixora.http.server.duration's platform-wide unit choice. */
    private const DURATION_UNIT = 'ms';

    private readonly Counter $commandTotal;

    private readonly Histogram $duration;

    private ?ConsoleContext $currentContext = null;

    private int $startedAt = 0;

    private bool $finishedForCurrent = false;

    public function __construct(
        private readonly Tracer $tracer,
        Meter $meter,
        private readonly ConsoleCommandNormalizer $normalizer,
        private readonly string $environment,
        private readonly string $serviceName,
    ) {
        $this->commandTotal = $meter->counter(
            self::METRIC_COMMAND_TOTAL,
            unit: '{command}',
            description: 'Total Artisan command executions, labeled by command and outcome.',
        );

        $this->duration = $meter->histogram(
            self::METRIC_DURATION,
            unit: self::DURATION_UNIT,
            description: 'Artisan command execution duration in milliseconds, labeled by command and outcome.',
        );
    }

    public function commandStarting(CommandStarting $event): void
    {
        $this->safely(function () use ($event) {
            $this->currentContext = new ConsoleContext($this->normalizer->normalize($event->command));
            $this->startedAt = hrtime(true);
            $this->finishedForCurrent = false;
        });
    }

    public function commandFinished(CommandFinished $event): void
    {
        $this->safely(function () use ($event) {
            if ($this->currentContext === null || $this->finishedForCurrent) {
                // No matching CommandStarting, or this attempt was already
                // recorded — never double-count (Part 11 scenario 14).
                return;
            }

            $this->finishedForCurrent = true;

            $durationMs = (hrtime(true) - $this->startedAt) / 1_000_000;
            $outcome = ConsoleOutcome::fromExitCode($event->exitCode);

            // Filled in here so ConsoleErrorContextLogTap — which reads
            // currentContext() later, once Laravel actually logs an
            // uncaught command exception — sees the real, final outcome.
            $context = $this->currentContext->withResult($event->exitCode, $outcome);
            $this->currentContext = $context;

            $labels = [
                'environment' => $this->environment,
                'service_name' => $this->serviceName,
                'command' => $context->command,
                'outcome' => $outcome->value,
            ];

            $this->commandTotal->add(1, $labels);
            $this->duration->record($durationMs, $labels);

            $span = $this->tracer->activeSpan();
            $span->setAttributes([
                'ixora.console.command' => $context->command,
                'ixora.console.outcome' => $outcome->value,
                'ixora.console.exit_code' => $event->exitCode,
            ]);

            if ($outcome === ConsoleOutcome::Failed) {
                // The underlying exception, when there is one, is already
                // recorded onto the per-command span by the existing
                // console auto-instrumentation (Part 1 review) — not
                // duplicated here, exactly like HttpRequestTelemetry does
                // for 5xx responses.
                $span->setError();
            }
        });
    }

    /**
     * The normalized context of the most recently started command, or null
     * before any command has run in this process. Read by
     * ConsoleErrorContextLogTap — see the class docblock §"Log correlation"
     * for why this intentionally outlives commandFinished().
     */
    public function currentContext(): ?ConsoleContext
    {
        return $this->currentContext;
    }

    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            // Intentionally swallowed — telemetry must never affect
            // command output or exit code (telemetry-availability-policy.md).
        }
    }
}
