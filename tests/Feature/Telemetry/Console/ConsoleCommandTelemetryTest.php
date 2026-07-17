<?php

use App\Telemetry\Console\ConsoleCommandNormalizer;
use App\Telemetry\Console\ConsoleCommandTelemetry;
use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Telemetry\RecordingMeter;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

/**
 * Phase 7B.2 — Console telemetry, exercised through the real
 * App\Telemetry\Console\ConsoleCommandTelemetry listener exactly as it is
 * registered in TelemetryServiceProvider. Dispatches CommandStarting /
 * CommandFinished directly rather than through $this->artisan() — Laravel
 * only wires these two events outside `APP_ENV=testing`
 * (ConsoleCommandTelemetry's own docblock, verified empirically), so a real
 * Artisan invocation inside this suite would never reach the listener at
 * all. Dispatching the same events the framework dispatches in production
 * is the standard Laravel pattern for testing event listeners.
 */
function fakeConsoleTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->bind(Meter::class, fn () => new RecordingMeter($recorder));
    app()->forgetInstance(ConsoleCommandTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, amount: int|float, attributes: array<string, mixed>}>
 */
function commandTotalCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->counterCalls,
        fn (array $call) => $call['name'] === 'ixora.console.command.total',
    ));
}

/**
 * @return list<array{name: string, value: int|float, attributes: array<string, mixed>}>
 */
function commandDurationCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->histogramCalls,
        fn (array $call) => $call['name'] === 'ixora.console.command.duration',
    ));
}

function dispatchCommandLifecycle(string $command, int $exitCode, array $arguments = []): void
{
    $input = new ArrayInput($arguments);
    $output = new NullOutput;

    Event::dispatch(new CommandStarting($command, $input, $output));
    Event::dispatch(new CommandFinished($command, $input, $output, $exitCode));
}

// 9. Successful command.
test('a successful command is counted once with a normalized command name, success outcome, and the exit code preserved on the span', function () {
    $recorder = fakeConsoleTelemetry();

    dispatchCommandLifecycle('queue:work', 0, ['--once' => true]);

    $totals = commandTotalCalls($recorder);
    $durations = commandDurationCalls($recorder);

    expect($totals)->toHaveCount(1)
        ->and($durations)->toHaveCount(1);

    $attributes = $totals[0]['attributes'];
    expect($attributes['command'])->toBe('queue:work')
        ->and($attributes['outcome'])->toBe('success')
        ->and($attributes)->toHaveKeys(['environment', 'service_name']);

    expect($durations[0]['value'])->toBeFloat()->toBeGreaterThanOrEqual(0.0);

    $spanAttributes = $recorder->mergedSpanAttributes();
    expect($spanAttributes['ixora.console.command'])->toBe('queue:work')
        ->and($spanAttributes['ixora.console.outcome'])->toBe('success')
        ->and($spanAttributes['ixora.console.exit_code'])->toBe(0)
        ->and($recorder->spanErrorCalls)->toBe(0)
        ->and($recorder->spanEndCalls)->toBe(0);

    expect(app(ConsoleCommandTelemetry::class)->currentContext()->exitCode)->toBe(0);
});

// 10. Failed command.
test('a failed command (non-zero exit code) is counted once with a failed outcome and marks the active span as an error', function () {
    $recorder = fakeConsoleTelemetry();

    dispatchCommandLifecycle('migrate', 1);

    $totals = commandTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    expect($totals[0]['attributes']['outcome'])->toBe('failed');

    expect($recorder->spanErrorCalls)->toBe(1)
        ->and($recorder->spanEndCalls)->toBe(0)
        // the exception itself, when there is one, is already recorded onto
        // the per-command span by the existing auto-instrumentation — not
        // duplicated here.
        ->and($recorder->spanExceptions)->toBe([]);

    $context = app(ConsoleCommandTelemetry::class)->currentContext();
    expect($context->exitCode)->toBe(1)
        ->and($context->outcome->value)->toBe('failed');
});

// 11. Unknown command.
test('a command with an unresolvable/empty name records a bounded fallback value without crashing', function () {
    $recorder = fakeConsoleTelemetry();

    dispatchCommandLifecycle('', 0);

    $totals = commandTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    expect($totals[0]['attributes']['command'])->toBe(ConsoleCommandNormalizer::UNKNOWN);
});

// 12. Arguments and options safety.
test('raw arguments and options never appear in console metric labels or span attributes', function () {
    $recorder = fakeConsoleTelemetry();

    dispatchCommandLifecycle('app:import-secrets', 0, [
        '--token' => 'sk_live_super_secret',
        'path' => '/etc/shadow',
    ]);

    $totals = commandTotalCalls($recorder);
    $serialized = json_encode($totals[0]['attributes']).json_encode($recorder->mergedSpanAttributes());

    expect($serialized)
        ->not->toContain('sk_live_super_secret')
        ->not->toContain('/etc/shadow')
        ->not->toContain('--token');

    expect($totals[0]['attributes'])->not->toHaveKeys(['argv', 'arguments', 'options']);
});

// 13. Telemetry failure. Mirrors the Queue suite's equivalent scenario — a
// broken Tracer, exercised via the try/catch (safely()) that
// commandFinished() actually wraps its body in.
test('a broken Tracer never changes the command exit code or prevents CommandFinished from completing', function () {
    $recorder = fakeConsoleTelemetry();
    app()->bind(Tracer::class, fn () => new class implements Tracer
    {
        public function startSpan(string $name, array $attributes = []): Span
        {
            throw new RuntimeException('tracer exploded');
        }

        public function activeSpan(): Span
        {
            throw new RuntimeException('tracer exploded');
        }

        public function currentContext(): ?TraceContext
        {
            return null;
        }

        public function currentTraceId(): ?string
        {
            return null;
        }

        public function currentSpanId(): ?string
        {
            return null;
        }
    });
    app()->forgetInstance(ConsoleCommandTelemetry::class);

    $observedExitCode = null;
    Event::listen(CommandFinished::class, function (CommandFinished $event) use (&$observedExitCode) {
        $observedExitCode = $event->exitCode;
    });

    dispatchCommandLifecycle('migrate:status', 0);

    expect($observedExitCode)->toBe(0);

    // The counter/histogram add() calls happen before the broken
    // Tracer::activeSpan() call — metrics are still recorded even though
    // span enrichment failed.
    expect(commandTotalCalls($recorder))->toHaveCount(1);
});

// 14. No double instrumentation.
test('one command lifecycle increments the command-total counter and duration histogram exactly once, never twice', function () {
    $recorder = fakeConsoleTelemetry();

    dispatchCommandLifecycle('cache:clear', 0);

    expect(commandTotalCalls($recorder))->toHaveCount(1)
        ->and(commandDurationCalls($recorder))->toHaveCount(1);
});

test('a second, unrelated CommandFinished for the same command name is never recorded twice', function () {
    $recorder = fakeConsoleTelemetry();

    $input = new ArrayInput([]);
    $output = new NullOutput;

    Event::dispatch(new CommandStarting('cache:clear', $input, $output));
    Event::dispatch(new CommandFinished('cache:clear', $input, $output, 0));
    // A stray duplicate CommandFinished (e.g. a framework/edge-case double
    // dispatch) must not double-count — see $finishedForCurrent in
    // ConsoleCommandTelemetry.
    Event::dispatch(new CommandFinished('cache:clear', $input, $output, 0));

    expect(commandTotalCalls($recorder))->toHaveCount(1);
});
