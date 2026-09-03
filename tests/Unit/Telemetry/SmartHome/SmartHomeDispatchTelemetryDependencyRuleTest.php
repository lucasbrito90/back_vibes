<?php

/**
 * Phase 7B.4.2 restatement (in addition to the generic, always-on scan in
 * tests/Unit/Telemetry/DependencyRuleTest.php) of the Dependency Rule,
 * scoped specifically to the files this phase adds: app/Telemetry/SmartHome.
 * Named independently so this file can run standalone, e.g. via
 * --filter=SmartHome.
 */
function findOpenTelemetryImportsUnderSmartHomeTelemetry(string $path): array
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

test('app/Telemetry/SmartHome never imports an OpenTelemetry SDK/API class', function () {
    $violations = findOpenTelemetryImportsUnderSmartHomeTelemetry(dirname(__DIR__, 4).'/app/Telemetry/SmartHome');

    expect($violations)->toBe([]);
});

test('app/Telemetry/SmartHome consumes only the Telemetry Contracts, never a concrete OpenTelemetry or Noop implementation', function () {
    $path = dirname(__DIR__, 4).'/app/Telemetry/SmartHome';
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

test('app/Telemetry/SmartHome never touches Vibe, Device, Schedule, Smart Home domain, controller, console, push, or provider code', function () {
    $path = dirname(__DIR__, 4).'/app/Telemetry/SmartHome';
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    $forbidden = '/^use\s+App\\\\(Models\\\\|SmartHome\\\\|Http\\\\|Console\\\\Commands\\\\|PushNotifications\\\\|Jobs\\\\|Services\\\\Scheduling\\\\)/m';

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (preg_match($forbidden, $contents)) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([]);
});

/**
 * Phase 7B.4.6 restatement: Business Metrics are now permitted, but only on
 * the two files this phase's own Metrics Design Review decided to
 * implement a metric on — SmartHomeActionTelemetry.php (`ixora.smart_home.
 * action.total`/`.duration`) and SmartHomeDispatchTelemetry.php
 * (`ixora.smart_home.dispatch.total`). Every other file under
 * app/Telemetry/SmartHome — SmartHomeProviderTelemetry.php (Design Record
 * decision: Reject, duplication with the Action counter — see
 * backend-smart-home-business-metrics.md) and every enum — must still
 * import zero metrics contracts. See
 * SmartHomeBusinessMetricsDependencyRuleTest.php for the full phase-specific
 * assertions this introduces.
 */
test('app/Telemetry/SmartHome creates metrics only on the two files this phase justified — SmartHomeProviderTelemetry and every enum still import zero Counter/Histogram/UpDownCounter/Meter', function () {
    $path = dirname(__DIR__, 4).'/app/Telemetry/SmartHome';
    $violations = [];

    $allowedToImportMetrics = [
        dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeActionTelemetry.php',
        dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeDispatchTelemetry.php',
    ];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    $forbidden = '/^use\s+App\\\\Telemetry\\\\Contracts\\\\(Counter|Histogram|UpDownCounter|Meter)\\\\?;/m';

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (in_array($file->getPathname(), $allowedToImportMetrics, true)) {
            continue;
        }

        if (preg_match($forbidden, file_get_contents($file->getPathname()))) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([]);
});

test('app/Telemetry/SmartHome never touches a logging facade or channel — no logging changes in this phase', function () {
    $path = dirname(__DIR__, 4).'/app/Telemetry/SmartHome';
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (preg_match('/^use\s+Illuminate\\\\Support\\\\Facades\\\\Log\\\\?;/m', $contents) || str_contains($contents, 'Log::')) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([]);
});
