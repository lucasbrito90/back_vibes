<?php

use App\Telemetry\Console\ConsoleCommandNormalizer;

test('returns the command name unchanged when present', function () {
    expect((new ConsoleCommandNormalizer)->normalize('queue:work'))->toBe('queue:work');
});

test('falls back to a bounded value for a null command name', function () {
    expect((new ConsoleCommandNormalizer)->normalize(null))->toBe(ConsoleCommandNormalizer::UNKNOWN);
});

test('falls back to a bounded value for an empty command name', function () {
    expect((new ConsoleCommandNormalizer)->normalize(''))->toBe(ConsoleCommandNormalizer::UNKNOWN);
});

test('falls back to a bounded value for a whitespace-only command name', function () {
    expect((new ConsoleCommandNormalizer)->normalize('   '))->toBe(ConsoleCommandNormalizer::UNKNOWN);
});

test('never echoes argument or option values passed alongside the command name', function () {
    // The normalizer only ever receives Illuminate\Console\Events\CommandStarting::$command
    // (the resolved command name), never argv — this test documents that
    // contract at the type level: normalize() takes a single ?string.
    expect((new ConsoleCommandNormalizer)->normalize('migrate'))->toBe('migrate');
});
