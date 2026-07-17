<?php

use App\Telemetry\Console\ConsoleOutcome;

test('the bounded set matches the spec exactly', function () {
    $values = array_map(fn (ConsoleOutcome $case) => $case->value, ConsoleOutcome::cases());

    expect($values)->toBe(['success', 'failed', 'cancelled', 'unknown']);
});

test('exit code 0 maps to success', function () {
    expect(ConsoleOutcome::fromExitCode(0))->toBe(ConsoleOutcome::Success);
});

test('any non-zero exit code maps to failed', function () {
    expect(ConsoleOutcome::fromExitCode(1))->toBe(ConsoleOutcome::Failed)
        ->and(ConsoleOutcome::fromExitCode(2))->toBe(ConsoleOutcome::Failed)
        ->and(ConsoleOutcome::fromExitCode(130))->toBe(ConsoleOutcome::Failed)
        ->and(ConsoleOutcome::fromExitCode(255))->toBe(ConsoleOutcome::Failed);
});
