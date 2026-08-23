<?php

/**
 * Phase 7B.4.6 — Business Metrics. Restates the Dependency Rule (in
 * addition to the generic, always-on scan in
 * tests/Unit/Telemetry/DependencyRuleTest.php, and the directory-wide scans
 * in SmartHomeDispatchTelemetryDependencyRuleTest.php and
 * SmartHomeActionTelemetryDependencyRuleTest.php, both updated by this
 * phase) scoped specifically to what this phase changes: metric names,
 * metric labels, and instrument-type choices on the two files whose Design
 * Records (backend-smart-home-business-metrics.md) concluded "Implement" —
 * SmartHomeActionTelemetry.php and SmartHomeDispatchTelemetry.php — and the
 * continued absence of any metric on every other file under
 * app/Telemetry/SmartHome, whose Design Records concluded "Reject" or
 * "Defer". Named independently so this file can run standalone, e.g.
 * --filter=SmartHomeBusinessMetrics.
 */
function smartHomeBusinessMetricsFiles(): array
{
    return [
        dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeActionTelemetry.php',
        dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeDispatchTelemetry.php',
    ];
}

test('SmartHomeDispatchTelemetry depends only on Telemetry Contracts (Tracer, Span, Meter, Counter) besides PHP built-ins', function () {
    $contents = file_get_contents(dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeDispatchTelemetry.php');

    preg_match_all('/^use\s+([^;]+);/m', $contents, $matches);

    $allowed = [
        'App\Telemetry\Contracts\Counter',
        'App\Telemetry\Contracts\Meter',
        'App\Telemetry\Contracts\Span',
        'App\Telemetry\Contracts\Tracer',
        'Throwable',
    ];

    expect($matches[1])->toEqualCanonicalizing($allowed);
});

test('SmartHomeDispatchTelemetry imports exactly Counter and Meter for its Business Metric — no Histogram or UpDownCounter (not justified by the Architecture Review)', function () {
    $contents = file_get_contents(dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeDispatchTelemetry.php');

    expect(preg_match('/^use\s+App\\\\Telemetry\\\\Contracts\\\\Counter;/m', $contents))->toBe(1)
        ->and(preg_match('/^use\s+App\\\\Telemetry\\\\Contracts\\\\Meter;/m', $contents))->toBe(1)
        ->and(preg_match('/^use\s+App\\\\Telemetry\\\\Contracts\\\\Histogram;/m', $contents))->toBe(0)
        ->and(preg_match('/^use\s+App\\\\Telemetry\\\\Contracts\\\\UpDownCounter;/m', $contents))->toBe(0);
});

test('every metric name recorded by this phase follows the ixora.smart_home.* naming convention and uses only the two names this phase\'s Design Records approved', function () {
    $expectedNames = [
        dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeActionTelemetry.php' => [
            'ixora.smart_home.action.total',
            'ixora.smart_home.action.duration',
        ],
        dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeDispatchTelemetry.php' => [
            'ixora.smart_home.dispatch.total',
        ],
    ];

    foreach ($expectedNames as $file => $names) {
        $contents = file_get_contents($file);

        preg_match_all("/'(ixora\\.smart_home\\.[a-z_.]+)'/", $contents, $matches);
        $foundMetricNames = array_values(array_unique($matches[1]));

        expect($foundMetricNames)->toEqualCanonicalizing($names, "{$file} must record exactly the metric names its own Design Record approved — no undocumented ixora.smart_home.* metric.");
    }
});

test('SmartHomeActionTelemetry and SmartHomeDispatchTelemetry record only the bounded label set their own Design Record allows — no forbidden or unbounded label', function () {
    // action_type is intentionally NOT forbidden — TD-2 (backend-business-
    // telemetry-validation.md §13) approved it as a bounded label on
    // SmartHomeActionTelemetry, normalized via SmartHomeActionType, in
    // Phase 7B.5. Every other identifier remains forbidden.
    $forbidden = [
        'action_id', 'device_id', 'entity_id', 'provider_device_id', 'provider_connection_id',
        'schedule_id', 'vibe_id', 'user_id', 'session_id', 'trace_id', 'span_id',
        'url', 'token', 'credential', 'payload', 'header', 'body', 'json',
    ];

    foreach (smartHomeBusinessMetricsFiles() as $file) {
        $contents = file_get_contents($file);

        // Every string literal that looks like a label key inside this
        // file's own labels()/recordCounter()/recordMetrics() helpers.
        preg_match_all("/'([a-z_]+)'\\s*=>/", $contents, $matches);
        $labelKeys = array_values(array_unique($matches[1]));

        foreach ($labelKeys as $key) {
            foreach ($forbidden as $needle) {
                expect(str_contains($key, $needle))->toBeFalse("{$file}: label key [{$key}] must not contain forbidden fragment [{$needle}] — metrics-philosophy.md §6.");
            }
        }
    }
});

test('SmartHomeActionTelemetry\'s metric label set is exactly {environment, service_name, outcome, provider, action_type} — action_type added by TD-2 (Phase 7B.5)', function () {
    $contents = file_get_contents(dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeActionTelemetry.php');

    preg_match('/private function recordMetrics\\(.*?\\n    \\}\\n/s', $contents, $match);
    expect($match)->not->toBeEmpty();

    preg_match_all("/'([a-z_]+)'\\s*=>/", $match[0], $matches);

    expect(array_values(array_unique($matches[1])))->toEqualCanonicalizing([
        'environment', 'service_name', 'outcome', 'provider', 'action_type',
    ]);
});

test('SmartHomeDispatchTelemetry\'s metric label set is exactly {environment, service_name, entry_point, outcome}', function () {
    $contents = file_get_contents(dirname(__DIR__, 4).'/app/Telemetry/SmartHome/SmartHomeDispatchTelemetry.php');

    preg_match('/private function recordCounter\\(.*?\\n    \\}\\n/s', $contents, $match);
    expect($match)->not->toBeEmpty();

    preg_match_all("/'([a-z_]+)'\\s*=>/", $match[0], $matches);

    expect(array_values(array_unique($matches[1])))->toEqualCanonicalizing([
        'environment', 'service_name', 'entry_point', 'outcome',
    ]);
});

/**
 * Design Record decision (backend-smart-home-business-metrics.md
 * §"Candidate: ixora.smart_home.provider.total"): Reject — 1:1 duplication
 * with the Action-level counter in today's single-provider-per-attempt
 * pipeline (already confirmed once by Phase 7B.4.4 §6.5, re-confirmed by
 * Phase 7B.4.5 §11). This test guards against that decision silently
 * regressing.
 */
test('SmartHomeProviderTelemetry and every SmartHome enum remain completely metric-free — the Design Record rejected a Provider-level metric', function () {
    $path = dirname(__DIR__, 4).'/app/Telemetry/SmartHome';
    $exempt = smartHomeBusinessMetricsFiles();
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    $forbidden = '/^use\s+App\\\\Telemetry\\\\Contracts\\\\(Counter|Histogram|UpDownCounter|Meter)\\\\?;/m';

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || in_array($file->getPathname(), $exempt, true)) {
            continue;
        }

        if (preg_match($forbidden, file_get_contents($file->getPathname()))) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([]);
});
