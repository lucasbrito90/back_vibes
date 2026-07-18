<?php

/**
 * Phase 7B.3 restatement (in addition to the generic, always-on scan in
 * tests/Unit/Telemetry/DependencyRuleTest.php, and the Phase 7B.2
 * restatement in tests/Unit/Telemetry/QueueConsole/
 * QueueConsoleTelemetryDependencyRuleTest.php) of the Dependency Rule,
 * scoped specifically to the files this phase adds: app/Telemetry/Scheduler
 * and App\Telemetry\Logging\SchedulerErrorContextLogTap. Named
 * independently so this file can run standalone, e.g. via --filter=Scheduler.
 */
function findOpenTelemetryImportsUnderScheduler(string $path): array
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

test('app/Telemetry/Scheduler never imports an OpenTelemetry SDK/API class', function () {
    $violations = findOpenTelemetryImportsUnderScheduler(dirname(__DIR__, 4).'/app/Telemetry/Scheduler');

    expect($violations)->toBe([]);
});

test('app/Telemetry/Logging/SchedulerErrorContextLogTap.php never imports an OpenTelemetry SDK/API class', function () {
    $violations = findOpenTelemetryImportsUnderScheduler(dirname(__DIR__, 4).'/app/Telemetry/Logging/SchedulerErrorContextLogTap.php');

    expect($violations)->toBe([]);
});

test('app/Telemetry/Scheduler consumes only the Telemetry Contracts, never a concrete OpenTelemetry or Noop implementation', function () {
    $path = dirname(__DIR__, 4).'/app/Telemetry/Scheduler';
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

test('app/Telemetry/Scheduler never touches Schedule model, Vibe, Smart Home, Push, or provider domain code', function () {
    $path = dirname(__DIR__, 4).'/app/Telemetry/Scheduler';
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    $forbidden = '/^use\s+App\\\\(Models\\\\(Schedule|Vibe|ScheduleExecution|User|Device)|SmartHome|PushNotifications|Services\\\\Scheduling)\\\\/m';

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
