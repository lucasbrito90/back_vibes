<?php

/**
 * Phase 7B.4.7 — Business Logging.
 *
 * Static code-analysis guards for the logging improvements applied to
 * App\Jobs\SmartHome\SmartHomeActionJob:
 *
 * - L-2 resolution: Log::info on success removed (metric + trace covers it).
 * - Security: provider_device_id removed from $context (naming-convention §8
 *   forbidden field — may reveal home layout).
 * - Vocabulary alignment: exception_class added to catch-block logs (replaces
 *   the raw error_message that could contain provider response text).
 * - Telemetry alignment: outcome added to every failure log, using the same
 *   SmartHomeActionOutcome vocabulary the Business Metrics already use, so
 *   log fields are directly cross-referenceable with metric dimensions.
 *
 * These tests run at the source-file level (no DB, no HTTP fakes) so they
 * can catch regressions that would otherwise slip through a functional test
 * written before the log-field constraint existed.
 */
function smartHomeActionJobSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 4).'/app/Jobs/SmartHome/SmartHomeActionJob.php');
}

test('SmartHomeActionJob never logs provider_device_id — forbidden field that reveals home layout', function () {
    expect(smartHomeActionJobSource())->not->toContain("'provider_device_id'");
});

test('SmartHomeActionJob never logs error_message — raw provider response, potential sensitive data', function () {
    expect(smartHomeActionJobSource())->not->toContain("'error_message'");
});

test('SmartHomeActionJob never emits Log::info — success is covered by metric + trace (L-2 resolution)', function () {
    expect(smartHomeActionJobSource())->not->toContain('Log::info(');
});

test('SmartHomeActionJob uses exception_class instead of error_message in catch blocks', function () {
    $source = smartHomeActionJobSource();

    expect($source)->toContain("'exception_class' => \$e::class");
});

test('SmartHomeActionJob includes outcome in every failure and unsupported log', function () {
    $source = smartHomeActionJobSource();

    // Every Log::warning / Log::error call in the failure path must carry
    // an outcome key so log fields are cross-referenceable with metrics.
    preg_match_all('/Log::(warning|error)\(.*?\]\);/s', $source, $matches);

    foreach ($matches[0] as $logCall) {
        // J1–J3 guard-clause logs (action not found / device missing /
        // connection missing) emit before the SmartHomeActionOutcome
        // vocabulary exists and intentionally have no outcome — skip them.
        if (str_contains($logCall, 'skipping')) {
            continue;
        }

        expect($logCall)->toContain("'outcome'");
    }
});

test('SmartHomeActionJob does not log the redundant success boolean — level already captures this', function () {
    $source = smartHomeActionJobSource();

    // 'success' was removed from every log context in Phase 7B.4.7.
    // The guard-clause skips use "skipping" messages and never had this key.
    // The only remaining 'success' references are the ActionResult property
    // access ($result->success) in the if-guard and the logResult check.
    preg_match_all('/Log::(info|warning|error)\([^)]+?\[([^\]]+)\]/s', $source, $matches);

    foreach ($matches[2] as $contextBody) {
        expect($contextBody)->not->toContain("'success'");
    }
});
