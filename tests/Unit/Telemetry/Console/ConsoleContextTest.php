<?php

use App\Telemetry\Console\ConsoleContext;
use App\Telemetry\Console\ConsoleOutcome;

test('toLogContext() exposes only command before a result is known', function () {
    $context = new ConsoleContext('migrate');

    expect($context->toLogContext())->toBe(['command' => 'migrate']);
});

test('withResult() adds exit_code and outcome without mutating the original', function () {
    $context = new ConsoleContext('migrate');
    $finished = $context->withResult(1, ConsoleOutcome::Failed);

    expect($context->toLogContext())->toBe(['command' => 'migrate'])
        ->and($finished->toLogContext())->toBe([
            'command' => 'migrate',
            'exit_code' => 1,
            'outcome' => 'failed',
        ]);
});
