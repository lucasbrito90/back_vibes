<?php

/**
 * Phase 7B.4.4 restatement (in addition to the generic, always-on scan in
 * tests/Unit/Telemetry/DependencyRuleTest.php, and the directory-wide scan
 * in SmartHomeDispatchTelemetryDependencyRuleTest.php, which already covers
 * every file under app/Telemetry/SmartHome including the ones this phase
 * adds) of the Dependency Rule, scoped specifically to the two files this
 * phase adds: SmartHomeProviderTelemetry.php, SmartHomeProviderDeviceDomain.php.
 * Named independently so this file can run standalone, e.g.
 * --filter=SmartHomeProvider.
 */
function smartHomeProviderTelemetryFiles(): array
{
    return [
        dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeProviderTelemetry.php',
        dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeProviderDeviceDomain.php',
    ];
}

test('the two files added by Phase 7B.4.4 exist', function () {
    foreach (smartHomeProviderTelemetryFiles() as $file) {
        expect(file_exists($file))->toBeTrue("Expected {$file} to exist.");
    }
});

test('SmartHomeProviderTelemetry never imports OpenTelemetry SDK/API, a concrete Noop implementation, Models, SmartHome domain code, Jobs, Controllers, Console, HTTP client, or PushNotifications', function () {
    $contents = file_get_contents(dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeProviderTelemetry.php');

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
        '/^use\s+GuzzleHttp\\\\/m' => 'Guzzle',
        '/^use\s+Illuminate\\\\Support\\\\Facades\\\\(Log|DB|Http)\\\\?;/m' => 'a Log/DB/Http facade',
    ];

    foreach ($forbiddenPatterns as $pattern => $label) {
        expect(preg_match($pattern, $contents))
            ->toBe(0, "SmartHomeProviderTelemetry.php must never import {$label}.");
    }
});

test('SmartHomeProviderTelemetry depends only on Telemetry Contracts (Tracer, Span) besides PHP built-ins', function () {
    // SmartHomeProviderDeviceDomain lives in the same App\Telemetry\SmartHome
    // namespace, so PHP resolves it with no `use` import at all — it is
    // still scanned and constrained by SmartHomeProviderDeviceDomain's own
    // "plain enum with no external imports" test above.
    $contents = file_get_contents(dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeProviderTelemetry.php');

    preg_match_all('/^use\s+([^;]+);/m', $contents, $matches);

    $allowed = [
        'App\Telemetry\Contracts\Span',
        'App\Telemetry\Contracts\Tracer',
        'Throwable',
    ];

    expect($matches[1])->toEqualCanonicalizing($allowed);
});

test('SmartHomeProviderDeviceDomain is a plain enum with no external imports', function () {
    $contents = file_get_contents(dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeProviderDeviceDomain.php');

    expect(preg_match('/^use\s+/m', $contents))->toBe(0, 'SmartHomeProviderDeviceDomain.php must have no imports at all.')
        ->and(preg_match('/^enum\s+SmartHomeProviderDeviceDomain:\s*string/m', $contents))->toBe(1);
});

test('app/Telemetry/SmartHome creates no metrics anywhere, including the new Provider files — no Counter, Histogram, or UpDownCounter contract import', function () {
    foreach (smartHomeProviderTelemetryFiles() as $file) {
        $contents = file_get_contents($file);

        expect(preg_match('/^use\s+App\\\\Telemetry\\\\Contracts\\\\(Counter|Histogram|UpDownCounter|Meter)\\\\?;/m', $contents))
            ->toBe(0, "{$file} must not import a metrics contract.");
    }
});

test('app/Telemetry/SmartHome never touches a logging facade in the new Provider files — no logging changes in this phase', function () {
    foreach (smartHomeProviderTelemetryFiles() as $file) {
        $contents = file_get_contents($file);

        expect(str_contains($contents, 'Log::'))->toBeFalse("{$file} must not call the Log facade.");
    }
});

test('SmartHomeProviderTelemetry records no url, http method, status code, or duration attribute of its own', function () {
    $contents = file_get_contents(dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeProviderTelemetry.php');

    $forbiddenAttributeNames = [
        'url.full',
        'url.path',
        'http.request.method',
        'http.response.status_code',
        'server.address',
        'duration',
    ];

    foreach ($forbiddenAttributeNames as $attribute) {
        expect(str_contains($contents, "'{$attribute}'"))
            ->toBeFalse("SmartHomeProviderTelemetry.php must not set the '{$attribute}' attribute — already owned by opentelemetry-auto-guzzle.");
    }
});
