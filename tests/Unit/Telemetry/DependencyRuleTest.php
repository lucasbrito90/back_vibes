<?php

/**
 * Enforces the Phase 7A Dependency Rule at the source-code level:
 *
 *   Domain -> Telemetry Contracts -> OpenTelemetry Implementation -> OpenTelemetry SDK
 *
 * Nothing outside app/Telemetry/OpenTelemetry may import an OpenTelemetry SDK
 * (or API) class directly. This is a plain filesystem/regex scan — no
 * Laravel bootstrap required — so it stays fast and cannot be bypassed by
 * container bindings.
 */
function otelImportsIn(string $path): array
{
    $violations = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

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

test('no OpenTelemetry SDK/API class is imported outside app/Telemetry/OpenTelemetry', function () {
    $appDir = dirname(__DIR__, 3).'/app';
    $violations = [];

    foreach (new DirectoryIterator($appDir) as $entry) {
        if ($entry->isDot() || ! $entry->isDir()) {
            continue;
        }

        if ($entry->getFilename() === 'Telemetry') {
            // Only App\Telemetry\OpenTelemetry may import the SDK — scan every
            // OTHER top-level subdirectory of the Telemetry module too.
            foreach (new DirectoryIterator($entry->getPathname()) as $telemetrySubdir) {
                if ($telemetrySubdir->isDot() || ! $telemetrySubdir->isDir()) {
                    continue;
                }

                if ($telemetrySubdir->getFilename() === 'OpenTelemetry') {
                    continue;
                }

                $violations = [...$violations, ...otelImportsIn($telemetrySubdir->getPathname())];
            }

            continue;
        }

        $violations = [...$violations, ...otelImportsIn($entry->getPathname())];
    }

    expect($violations)->toBe([]);
});

test('app/Telemetry/Contracts never imports the OpenTelemetry SDK', function () {
    $violations = otelImportsIn(dirname(__DIR__, 3).'/app/Telemetry/Contracts');

    expect($violations)->toBe([]);
});

test('app/Telemetry/Noop never imports the OpenTelemetry SDK or App\Telemetry\OpenTelemetry', function () {
    $path = dirname(__DIR__, 3).'/app/Telemetry/Noop';
    $violations = otelImportsIn($path);

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('/^use\s+App\\\\Telemetry\\\\OpenTelemetry\\\\/m', file_get_contents($file->getPathname()))) {
            $violations[] = $file->getPathname().' imports an App\\Telemetry\\OpenTelemetry class';
        }
    }

    expect($violations)->toBe([]);
});

test('app/Telemetry/OpenTelemetry classes only implement Telemetry Contracts, never Business/Domain contracts', function () {
    $path = dirname(__DIR__, 3).'/app/Telemetry/OpenTelemetry';
    $violations = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (preg_match_all('/^use\s+((?!OpenTelemetry\\\\|App\\\\Telemetry\\\\)[^;]+);/m', $contents, $matches)) {
            foreach ($matches[1] as $import) {
                if (str_starts_with($import, 'Throwable')) {
                    continue;
                }

                // app/Telemetry/OpenTelemetry/Logging/ bridges OTel with Monolog —
                // Monolog classes are a legitimate dependency in that sub-namespace only.
                if (
                    str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'OpenTelemetry'.DIRECTORY_SEPARATOR.'Logging'.DIRECTORY_SEPARATOR)
                    && str_starts_with($import, 'Monolog\\')
                ) {
                    continue;
                }

                $violations[] = $file->getPathname().' imports unexpected dependency '.$import;
            }
        }
    }

    expect($violations)->toBe([]);
});
