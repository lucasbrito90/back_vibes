<?php

/**
 * Phase 7B.4.3 restatement (in addition to the generic, always-on scan in
 * tests/Unit/Telemetry/DependencyRuleTest.php, and the directory-wide scan
 * in SmartHomeDispatchTelemetryDependencyRuleTest.php, which already covers
 * every file under app/Telemetry/SmartHome including the ones this phase
 * adds) of the Dependency Rule, scoped specifically to the three files this
 * phase adds: SmartHomeActionTelemetry.php, SmartHomeActionProvider.php,
 * SmartHomeActionOutcome.php. Named independently so this file can run
 * standalone, e.g. via --filter=SmartHomeAction.
 */
function smartHomeActionTelemetryFiles(): array
{
    return [
        dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeActionTelemetry.php',
        dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeActionProvider.php',
        dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeActionOutcome.php',
    ];
}

test('the three files added by Phase 7B.4.3 exist', function () {
    foreach (smartHomeActionTelemetryFiles() as $file) {
        expect(file_exists($file))->toBeTrue("Expected {$file} to exist.");
    }
});

test('SmartHomeActionTelemetry never imports OpenTelemetry SDK/API, a concrete Noop implementation, Models, SmartHome domain code, Jobs, Controllers, Console, or PushNotifications', function () {
    $contents = file_get_contents(dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeActionTelemetry.php');

    $forbiddenPatterns = [
        '/^use\s+OpenTelemetry\\\\/m' => 'OpenTelemetry SDK/API',
        '/^use\s+App\\\\Telemetry\\\\(OpenTelemetry|Noop)\\\\/m' => 'a concrete Telemetry implementation',
        '/^use\s+App\\\\Models\\\\/m' => 'a Model',
        '/^use\s+App\\\\SmartHome\\\\/m' => 'Smart Home domain code',
        '/^use\s+App\\\\Jobs\\\\/m' => 'a Job',
        '/^use\s+App\\\\Http\\\\Controllers\\\\/m' => 'a Controller',
        '/^use\s+App\\\\Console\\\\Commands\\\\/m' => 'a Console command',
        '/^use\s+App\\\\PushNotifications\\\\/m' => 'PushNotifications',
        '/^use\s+Illuminate\\\\Queue\\\\/m' => 'Illuminate Queue',
        '/^use\s+Illuminate\\\\Http\\\\/m' => 'Illuminate HTTP',
        '/^use\s+Illuminate\\\\Support\\\\Facades\\\\(Log|DB)\\\\?;/m' => 'a Log/DB facade',
    ];

    foreach ($forbiddenPatterns as $pattern => $label) {
        expect(preg_match($pattern, $contents))
            ->toBe(0, "SmartHomeActionTelemetry.php must never import {$label}.");
    }
});

test('SmartHomeActionTelemetry depends only on Telemetry Contracts (Tracer, Span) besides PHP built-ins', function () {
    $contents = file_get_contents(dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeActionTelemetry.php');

    preg_match_all('/^use\s+([^;]+);/m', $contents, $matches);

    $allowed = [
        'App\Telemetry\Contracts\Span',
        'App\Telemetry\Contracts\Tracer',
        'Throwable',
    ];

    expect($matches[1])->toEqualCanonicalizing($allowed);
});

test('SmartHomeActionProvider and SmartHomeActionOutcome are plain enums with no external imports', function () {
    foreach (['SmartHomeActionProvider', 'SmartHomeActionOutcome'] as $enum) {
        $contents = file_get_contents(dirname(__DIR__, 4)."/app/Telemetry/SmartHome/{$enum}.php");

        expect(preg_match('/^use\s+/m', $contents))->toBe(0, "{$enum}.php must have no imports at all.")
            ->and(preg_match('/^enum\s+'.$enum.':\s*string/m', $contents))->toBe(1);
    }
});

test('app/Telemetry/SmartHome creates no metrics anywhere, including the new Action files — no Counter, Histogram, or UpDownCounter contract import', function () {
    foreach (smartHomeActionTelemetryFiles() as $file) {
        $contents = file_get_contents($file);

        expect(preg_match('/^use\s+App\\\\Telemetry\\\\Contracts\\\\(Counter|Histogram|UpDownCounter|Meter)\\\\?;/m', $contents))
            ->toBe(0, "{$file} must not import a metrics contract.");
    }
});

test('app/Telemetry/SmartHome never touches a logging facade in the new Action files — no logging changes in this phase', function () {
    foreach (smartHomeActionTelemetryFiles() as $file) {
        $contents = file_get_contents($file);

        expect(str_contains($contents, 'Log::'))->toBeFalse("{$file} must not call the Log facade.");
    }
});
