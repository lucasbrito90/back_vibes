<?php

/**
 * Phase 7B.1 restatement (in addition to the generic, always-on scan in
 * tests/Unit/Telemetry/DependencyRuleTest.php) of the Dependency Rule,
 * scoped specifically to the files this phase adds: app/Telemetry/Http and
 * the HTTP lifecycle integration point app/Http/Middleware/HttpTelemetryMiddleware.php.
 * Named independently (not reusing DependencyRuleTest's helper) so this file
 * can run standalone, e.g. via --filter=Http.
 */
function findOpenTelemetryImportsUnder(string $path): array
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

test('app/Telemetry/Http never imports an OpenTelemetry SDK/API class', function () {
    $violations = findOpenTelemetryImportsUnder(dirname(__DIR__, 4).'/app/Telemetry/Http');

    expect($violations)->toBe([]);
});

test('app/Http/Middleware/HttpTelemetryMiddleware.php never imports an OpenTelemetry SDK/API class', function () {
    $violations = findOpenTelemetryImportsUnder(dirname(__DIR__, 4).'/app/Http/Middleware/HttpTelemetryMiddleware.php');

    expect($violations)->toBe([]);
});

test('app/Telemetry/Http consumes only the Telemetry Contracts, never a concrete OpenTelemetry or Noop implementation', function () {
    $path = dirname(__DIR__, 4).'/app/Telemetry/Http';
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
