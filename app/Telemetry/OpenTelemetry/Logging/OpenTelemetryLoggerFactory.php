<?php

declare(strict_types=1);

namespace App\Telemetry\OpenTelemetry\Logging;

use Monolog\Handler\AbstractHandler;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Contrib\Logs\Monolog\Handler as OtelMonologHandler;
use Throwable;

/**
 * Custom Monolog channel factory for the "otel" log channel
 * (Phase 8.8.8 — Backend OTLP Log Export).
 *
 * Retrieves Globals::loggerProvider() — populated at Composer autoload time
 * by OpenTelemetry\SDK\SdkAutoloader when OTEL_PHP_AUTOLOAD_ENABLED=true —
 * and wraps it in exactly one OpenTelemetry\Contrib\Logs\Monolog\Handler,
 * protected by FailSafeOtelHandler.
 *
 * This class NEVER:
 * - constructs a LoggerProvider, LoggerProviderBuilder, or exporter;
 * - calls Globals::registerInitializer(…);
 * - writes to the OTLP channel from its own failure path (avoids recursion);
 * - exposes header values or bearer tokens in any code path.
 *
 * Failure isolation: the returned handler is wrapped in FailSafeOtelHandler
 * so any exception thrown by the OTLP exporter during write() is swallowed
 * at the handler boundary. An unreachable Collector, expired token, full
 * BatchLogRecordProcessor queue, or serialization failure never propagates
 * into Monolog's chain and never reaches the stderr handler or the
 * application.
 *
 * Log tap injection: TelemetryServiceProvider::registerLogTaps() iterates
 * all logging.channels keys and injects the 5 correlation taps into every
 * channel automatically — trace_id and span_id flow into the OTel LogRecord
 * as context.trace_id and extra.trace_id attributes exactly as they do on
 * the stderr channel.
 *
 * OTEL_PHP_MONOLOG_ATTRIB_MODE: the default "psr3" mode serialises context
 * and extra sub-keys as "context.<key>" and "extra.<key>" attributes
 * respectively, producing stable, bounded attribute names.
 */
final class OpenTelemetryLoggerFactory
{
    /**
     * Called by Laravel when resolving the "otel" logging channel.
     *
     * @param  array<string, mixed>  $config  The channel config array from config/logging.php.
     */
    public function __invoke(array $config): Logger
    {
        $level = $config['level'] ?? 'info';
        $handler = $this->buildHandler($level);

        return new Logger('otel', [$handler]);
    }

    private function buildHandler(mixed $level): AbstractHandler
    {
        try {
            $loggerProvider = Globals::loggerProvider();
            $otelHandler = new OtelMonologHandler($loggerProvider, $level, bubble: false);

            return new FailSafeOtelHandler($otelHandler);
        } catch (Throwable) {
            // If Globals::loggerProvider() is unavailable (SDK not bootstrapped,
            // or SDK explicitly disabled via OTEL_SDK_DISABLED=true), fall back
            // to a NullHandler so the "otel" channel is always a valid Monolog
            // handler — the stack channel (stderr,otel) continues writing to
            // stderr without interruption.
            return new NullHandler;
        }
    }
}
