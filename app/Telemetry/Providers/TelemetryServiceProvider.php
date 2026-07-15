<?php

declare(strict_types=1);

namespace App\Telemetry\Providers;

use App\Telemetry\Contracts\LoggerCorrelation;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\TelemetryManager;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Logging\TraceCorrelationLogTap;
use App\Telemetry\Noop\NoopTelemetryManager;
use App\Telemetry\OpenTelemetry\OpenTelemetryManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Single wiring point for the Telemetry Abstraction Layer
 * (backend-sdk-foundation.md §"Dependency injection").
 *
 * This is the ONLY class outside App\Telemetry\OpenTelemetry that is allowed
 * to reference App\Telemetry\OpenTelemetry\OpenTelemetryManager directly —
 * every other consumer (present or future) resolves TelemetryManager,
 * Tracer, Meter, or LoggerCorrelation from the container.
 *
 * Binding OpenTelemetryManager vs NoopTelemetryManager here — instead of at
 * every call site — is what makes "swap the SDK with zero domain changes"
 * (Future Compatibility, Phase 7A) possible: a future phase only ever needs
 * to change what gets bound in this one file.
 */
final class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/telemetry.php', 'telemetry');

        $this->app->singleton(TelemetryManager::class, function (Application $app) {
            $enabled = (bool) $app['config']->get('telemetry.enabled', true);

            if (! $enabled) {
                return new NoopTelemetryManager;
            }

            try {
                return new OpenTelemetryManager(enabled: true);
            } catch (Throwable) {
                // Bootstrapping the OpenTelemetry implementation must never take the
                // application down — fall back to the safe no-op implementation
                // (telemetry-availability-policy.md).
                return new NoopTelemetryManager;
            }
        });

        $this->app->bind(Tracer::class, fn (Application $app) => $app->make(TelemetryManager::class)->tracer());
        $this->app->bind(Meter::class, fn (Application $app) => $app->make(TelemetryManager::class)->meter());
        $this->app->bind(
            LoggerCorrelation::class,
            fn (Application $app) => $app->make(TelemetryManager::class)->loggerCorrelation(),
        );
    }

    public function boot(): void
    {
        $this->registerLogCorrelationTap();
        $this->registerFlushOnTermination();
    }

    /**
     * Adds TraceCorrelationLogTap to every configured log channel without
     * editing config/logging.php — trace_id / span_id then appear in the
     * `extra` bag of every log record for every channel automatically
     * (logs-philosophy.md §6).
     */
    private function registerLogCorrelationTap(): void
    {
        $config = $this->app['config'];
        $channels = (array) $config->get('logging.channels', []);

        foreach (array_keys($channels) as $channel) {
            $tap = (array) $config->get("logging.channels.{$channel}.tap", []);

            if (! in_array(TraceCorrelationLogTap::class, $tap, true)) {
                $tap[] = TraceCorrelationLogTap::class;
                $config->set("logging.channels.{$channel}.tap", $tap);
            }
        }
    }

    /**
     * Best-effort flush when the application finishes handling a request,
     * job, or command — in addition to (not instead of) the OpenTelemetry
     * SDK's own process-exit shutdown handler registered by SdkAutoloader.
     * Never throws and never delays the response
     * (telemetry-availability-policy.md R3).
     */
    private function registerFlushOnTermination(): void
    {
        $this->app->terminating(static function (Application $app) {
            try {
                $app->make(TelemetryManager::class)->flush();
            } catch (Throwable) {
                // Best-effort only — a flush failure must never surface here.
            }
        });
    }
}
