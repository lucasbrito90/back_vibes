<?php

/**
 * Phase 7B.2 restatement (in addition to the generic, always-on scan in
 * tests/Unit/Telemetry/DependencyRuleTest.php) of the Dependency Rule,
 * scoped specifically to the files this phase adds: app/Telemetry/Queue,
 * app/Telemetry/Console, and the two new log taps in app/Telemetry/Logging.
 * Named independently (not reusing DependencyRuleTest's helper) so this
 * file can run standalone, e.g. via --filter=Queue or --filter=Console.
 */
function findOpenTelemetryImportsUnderQueueConsole(string $path): array
{
    $violations = [];
    $files = is_dir($path)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
        : [new SplFileInfo($path)];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (preg_match_all('/^use\s+(OpenTelemetry\\\\[^;]+);/m', $contents, $matches)) {
            foreach ($matches[1] as $import) {
                $violations[] = $file->getPathname().' imports '.$import;
            }
        }
    }

    return $violations;
}

test('app/Telemetry/Queue never imports an OpenTelemetry SDK/API class', function () {
    $violations = findOpenTelemetryImportsUnderQueueConsole(dirname(__DIR__, 4).'/app/Telemetry/Queue');

    expect($violations)->toBe([]);
});

test('app/Telemetry/Console never imports an OpenTelemetry SDK/API class', function () {
    $violations = findOpenTelemetryImportsUnderQueueConsole(dirname(__DIR__, 4).'/app/Telemetry/Console');

    expect($violations)->toBe([]);
});

test('app/Telemetry/Logging/QueueErrorContextLogTap.php never imports an OpenTelemetry SDK/API class', function () {
    $violations = findOpenTelemetryImportsUnderQueueConsole(dirname(__DIR__, 4).'/app/Telemetry/Logging/QueueErrorContextLogTap.php');

    expect($violations)->toBe([]);
});

test('app/Telemetry/Logging/ConsoleErrorContextLogTap.php never imports an OpenTelemetry SDK/API class', function () {
    $violations = findOpenTelemetryImportsUnderQueueConsole(dirname(__DIR__, 4).'/app/Telemetry/Logging/ConsoleErrorContextLogTap.php');

    expect($violations)->toBe([]);
});

test('app/Telemetry/Queue consumes only the Telemetry Contracts, never a concrete OpenTelemetry or Noop implementation', function () {
    $path = dirname(__DIR__, 4).'/app/Telemetry/Queue';
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (preg_match('/^use\s+App\\\\Telemetry\\\\(OpenTelemetry|Noop)\\\\/m', $contents)) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([]);
});

test('app/Telemetry/Console consumes only the Telemetry Contracts, never a concrete OpenTelemetry or Noop implementation', function () {
    $path = dirname(__DIR__, 4).'/app/Telemetry/Console';
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (preg_match('/^use\s+App\\\\Telemetry\\\\(OpenTelemetry|Noop)\\\\/m', $contents)) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([]);
});
