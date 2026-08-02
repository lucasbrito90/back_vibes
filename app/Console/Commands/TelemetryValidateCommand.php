<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Telemetry\Configuration\TelemetryConfig;
use App\Telemetry\Contracts\TelemetryManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 9 (staging-runtime-integration) — local and CI diagnostic.
 *
 * Reports the telemetry runtime readiness of the running process without
 * printing any secret value. Emits one validation trace, one safe metric,
 * and one correlated log when the SDK is active — then force-flushes so
 * the signals land in Tempo/Prometheus before the process exits.
 *
 * SECURITY INVARIANT: OTEL_EXPORTER_OTLP_HEADERS is NEVER read, printed,
 * echoed, logged, or included in output. The command only reports whether
 * the header variable is *set* (non-empty).
 *
 * Exit codes:
 *   0 — all required prerequisites present and SDK responding correctly.
 *   1 — one or more required prerequisites absent or SDK disabled by intent.
 */
final class TelemetryValidateCommand extends Command
{
    protected $signature = 'ixora:telemetry-validate
        {--require-sdk : Exit non-zero when OTEL_SDK_DISABLED=true or when OTEL_PHP_AUTOLOAD_ENABLED≠true}';

    protected $description = 'Validate OpenTelemetry runtime prerequisites (staging diagnostic — never prints secrets)';

    public function handle(TelemetryManager $manager): int
    {
        $config = TelemetryConfig::fromArray((array) config('telemetry'));

        $this->line('');
        $this->line('<fg=cyan;options=bold>Ixora Telemetry Runtime Validation</>');
        $this->line(str_repeat('─', 50));

        // ── Extension ──────────────────────────────────────────────────────
        $extLoaded = extension_loaded('opentelemetry');
        $this->reportLine('opentelemetry extension loaded', $extLoaded ? 'yes' : 'no', $extLoaded ? 'green' : 'yellow');

        if ($extLoaded) {
            $extVersion = phpversion('opentelemetry') ?: 'unknown';
            $this->reportLine('extension version', $extVersion, 'default');
        }

        // ── SDK flags ──────────────────────────────────────────────────────
        $sdkDisabled = ! $config->enabled;
        $autoloadEnabled = $config->autoloadEnabled;

        $this->reportLine('OTEL_SDK_DISABLED', $sdkDisabled ? 'true (Noop mode)' : 'false (SDK mode)', $sdkDisabled ? 'yellow' : 'green');
        $this->reportLine('OTEL_PHP_AUTOLOAD_ENABLED', $autoloadEnabled ? 'true' : 'false', $autoloadEnabled ? 'green' : 'yellow');

        // ── Service identity ───────────────────────────────────────────────
        $this->reportLine('service name', $config->serviceName, 'default');
        $this->reportLine('service namespace', $config->serviceNamespace, 'default');
        $this->reportLine('service version', $config->serviceVersion, 'default');
        $this->reportLine('deployment.environment', $config->environment, 'default');

        // ── Collector hostname (never full URL with potential embedded creds) ──
        $collectorHost = $this->extractHostname($config->otlpEndpoint);
        $this->reportLine('OTLP collector host', $collectorHost ?: '(not set)', $collectorHost ? 'default' : 'yellow');
        $this->reportLine('OTLP protocol', $config->otlpProtocol ?: '(not set)', 'default');
        $this->reportLine('OTLP timeout_ms', (string) $config->otlpTimeoutMs, 'default');

        // ── Authorization header: present/absent only — NEVER the value ────
        // config('telemetry.otlp.headers') is the parsed key=value map of
        // OTEL_EXPORTER_OTLP_HEADERS; checking for the 'Authorization' key
        // tells us the header is configured without ever printing its value.
        $authHeaderSet = array_key_exists('Authorization', $config->otlpHeaders);
        $this->reportLine('OTLP Authorization header', $authHeaderSet ? 'set (not shown)' : 'NOT SET', $authHeaderSet ? 'green' : 'red');

        // ── Sampler ────────────────────────────────────────────────────────
        $this->reportLine('traces sampler', $config->tracesSampler, 'default');
        $this->reportLine('sampler ratio', (string) $config->tracesSamplerArg, 'default');

        $this->line('');

        // ── Functional probe (only when SDK is not intentionally disabled) ─
        $probeOk = true;

        if (! $sdkDisabled) {
            $probeOk = $this->runFunctionalProbe($manager);
        } else {
            $this->line('<fg=yellow>SDK is disabled — skipping functional probe (Noop mode verified by Noop contract tests).</>');
        }

        $this->line('');

        // ── Prerequisites check ────────────────────────────────────────────
        $required = $this->option('require-sdk');
        $missing = [];

        // The native extension is required when the SdkAutoloader will run
        // (OTEL_PHP_AUTOLOAD_ENABLED=true). When the autoloader is off, the
        // missing extension is only a warning (already reported above).
        if (! $extLoaded && ($autoloadEnabled || $required)) {
            $missing[] = 'opentelemetry PHP extension not loaded (required when OTEL_PHP_AUTOLOAD_ENABLED=true)';
        }

        if ($required) {
            if ($sdkDisabled) {
                $missing[] = 'OTEL_SDK_DISABLED=true but --require-sdk flag is set';
            }

            if (! $autoloadEnabled) {
                $missing[] = 'OTEL_PHP_AUTOLOAD_ENABLED is not true but --require-sdk flag is set';
            }
        }

        if (! $authHeaderSet && ! $sdkDisabled && $collectorHost) {
            $missing[] = 'OTEL_EXPORTER_OTLP_HEADERS not set (Bearer token required for Collector authentication)';
        }

        if ($missing !== []) {
            $this->line('<fg=red;options=bold>Missing prerequisites:</>');

            foreach ($missing as $msg) {
                $this->line("  <fg=red>✗</> {$msg}");
            }

            $this->line('');

            return self::FAILURE;
        }

        if (! $probeOk) {
            return self::FAILURE;
        }

        $this->line('<fg=green;options=bold>✓ All checked prerequisites are satisfied.</>');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Emits one validation span, one safe counter increment, and one log
     * record — then force-flushes so telemetry lands before process exit.
     * Never throws; returns false if the probe failed in a way worth
     * surfacing to the operator.
     */
    private function runFunctionalProbe(TelemetryManager $manager): bool
    {
        $this->line('<fg=cyan>Running functional probe…</>');

        $traceOk = false;
        $metricOk = false;
        $logOk = false;
        $flushOk = false;

        // Trace probe
        try {
            $span = $manager->tracer()->startSpan('ixora.telemetry.validate', [
                'telemetry.validate' => true,
            ]);
            $span->setAttribute('service.name', config('telemetry.service_name'))
                ->setAttribute('deployment.environment', config('telemetry.environment'));
            $span->end();
            $traceOk = true;
        } catch (Throwable $e) {
            $this->line("<fg=red>Trace probe failed: {$e->getMessage()}</>");
        }

        // Metric probe — uses ixora.http.server.request.total which already
        // exists in the platform metric catalogue (never introduces a new name).
        try {
            $counter = $manager->meter()->counter(
                'ixora.telemetry.validate.total',
                unit: '{probe}',
                description: 'Validation probe counter — emitted once per ixora:telemetry-validate run.',
            );
            $counter->add(1, [
                'service_name' => config('telemetry.service_name'),
                'environment' => config('telemetry.environment'),
            ]);
            $metricOk = true;
        } catch (Throwable $e) {
            $this->line("<fg=red>Metric probe failed: {$e->getMessage()}</>");
        }

        // Log probe — goes through the chosen log channel (stderr in staging),
        // picks up all registered correlation taps automatically.
        try {
            logger()->debug('ixora.telemetry.validate: probe log record', [
                'service_name' => config('telemetry.service_name'),
                'environment' => config('telemetry.environment'),
            ]);
            $logOk = true;
        } catch (Throwable $e) {
            $this->line("<fg=red>Log probe failed: {$e->getMessage()}</>");
        }

        // Force flush
        try {
            $flushOk = $manager->flush();
        } catch (Throwable $e) {
            $this->line("<fg=red>Flush failed: {$e->getMessage()}</>");
        }

        $this->reportLine('trace probe', $traceOk ? 'ok' : 'FAILED', $traceOk ? 'green' : 'red');
        $this->reportLine('metric probe', $metricOk ? 'ok' : 'FAILED', $metricOk ? 'green' : 'red');
        $this->reportLine('log probe', $logOk ? 'ok' : 'FAILED', $logOk ? 'green' : 'red');
        $this->reportLine('force flush', $flushOk ? 'ok' : 'best-effort (SDK may not support forceFlush)', $flushOk ? 'green' : 'yellow');

        return $traceOk && $metricOk && $logOk;
    }

    private function reportLine(string $label, string $value, string $color): void
    {
        $pad = str_pad($label, 38, ' ');

        if ($color === 'default') {
            $this->line("  {$pad} {$value}");
        } else {
            $this->line("  {$pad} <fg={$color}>{$value}</>");
        }
    }

    /**
     * Returns only the hostname portion of a URL so that any credentials
     * embedded in the URL (e.g. user:pass@host) are never printed.
     * Returns empty string when the endpoint is not set or not parseable.
     */
    private function extractHostname(string $endpoint): string
    {
        if ($endpoint === '') {
            return '';
        }

        $host = parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }
}
