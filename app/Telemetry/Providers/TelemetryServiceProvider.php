<?php

declare(strict_types=1);

namespace App\Telemetry\Providers;

use App\Telemetry\Console\ConsoleCommandNormalizer;
use App\Telemetry\Console\ConsoleCommandTelemetry;
use App\Telemetry\Contracts\LoggerCorrelation;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\TelemetryManager;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Http\HttpRequestTelemetry;
use App\Telemetry\Http\HttpRouteNormalizer;
use App\Telemetry\Logging\ConsoleErrorContextLogTap;
use App\Telemetry\Logging\HttpErrorContextLogTap;
use App\Telemetry\Logging\QueueErrorContextLogTap;
use App\Telemetry\Logging\SchedulerErrorContextLogTap;
use App\Telemetry\Logging\TraceCorrelationLogTap;
use App\Telemetry\Noop\NoopTelemetryManager;
use App\Telemetry\OpenTelemetry\OpenTelemetryManager;
use App\Telemetry\PushNotifications\PushNotificationTelemetry;
use App\Telemetry\PushNotifications\PushProviderTelemetry;
use App\Telemetry\Queue\QueueExecutionTelemetry;
use App\Telemetry\Queue\QueueJobNormalizer;
use App\Telemetry\Scheduler\SchedulerEventNormalizer;
use App\Telemetry\Scheduler\SchedulerExecutionTelemetry;
use App\Telemetry\SmartHome\SmartHomeActionTelemetry;
use App\Telemetry\SmartHome\SmartHomeDispatchTelemetry;
use App\Telemetry\SmartHome\SmartHomeProviderTelemetry;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;
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

        $this->app->singleton(HttpRequestTelemetry::class, function (Application $app) {
            $config = $app['config'];

            return new HttpRequestTelemetry(
                tracer: $app->make(Tracer::class),
                meter: $app->make(Meter::class),
                routeNormalizer: new HttpRouteNormalizer,
                environment: (string) $config->get('telemetry.environment', 'development'),
                serviceName: (string) $config->get('telemetry.service_name', 'back_vibes-api'),
            );
        });

        $this->app->singleton(QueueExecutionTelemetry::class, function (Application $app) {
            $config = $app['config'];

            return new QueueExecutionTelemetry(
                tracer: $app->make(Tracer::class),
                meter: $app->make(Meter::class),
                normalizer: new QueueJobNormalizer,
                environment: (string) $config->get('telemetry.environment', 'development'),
                serviceName: (string) $config->get('telemetry.service_name', 'back_vibes-api'),
            );
        });

        $this->app->singleton(ConsoleCommandTelemetry::class, function (Application $app) {
            $config = $app['config'];

            return new ConsoleCommandTelemetry(
                tracer: $app->make(Tracer::class),
                meter: $app->make(Meter::class),
                normalizer: new ConsoleCommandNormalizer,
                environment: (string) $config->get('telemetry.environment', 'development'),
                serviceName: (string) $config->get('telemetry.service_name', 'back_vibes-api'),
            );
        });

        $this->app->singleton(SchedulerExecutionTelemetry::class, function (Application $app) {
            $config = $app['config'];

            return new SchedulerExecutionTelemetry(
                tracer: $app->make(Tracer::class),
                meter: $app->make(Meter::class),
                normalizer: new SchedulerEventNormalizer,
                environment: (string) $config->get('telemetry.environment', 'development'),
                serviceName: (string) $config->get('telemetry.service_name', 'back_vibes-api'),
            );
        });

        $this->app->singleton(SmartHomeDispatchTelemetry::class, function (Application $app) {
            $config = $app['config'];

            return new SmartHomeDispatchTelemetry(
                tracer: $app->make(Tracer::class),
                meter: $app->make(Meter::class),
                environment: (string) $config->get('telemetry.environment', 'development'),
                serviceName: (string) $config->get('telemetry.service_name', 'back_vibes-api'),
            );
        });

        $this->app->singleton(SmartHomeActionTelemetry::class, function (Application $app) {
            $config = $app['config'];

            return new SmartHomeActionTelemetry(
                tracer: $app->make(Tracer::class),
                meter: $app->make(Meter::class),
                environment: (string) $config->get('telemetry.environment', 'development'),
                serviceName: (string) $config->get('telemetry.service_name', 'back_vibes-api'),
            );
        });

        $this->app->singleton(SmartHomeProviderTelemetry::class, function (Application $app) {
            return new SmartHomeProviderTelemetry(
                tracer: $app->make(Tracer::class),
            );
        });

        $this->app->singleton(PushNotificationTelemetry::class, function (Application $app) {
            $config = $app['config'];

            return new PushNotificationTelemetry(
                meter: $app->make(Meter::class),
                environment: (string) $config->get('telemetry.environment', 'development'),
                serviceName: (string) $config->get('telemetry.service_name', 'back_vibes-api'),
            );
        });

        $this->app->singleton(PushProviderTelemetry::class, function (Application $app) {
            return new PushProviderTelemetry(
                tracer: $app->make(Tracer::class),
            );
        });
    }

    public function boot(): void
    {
        $this->registerLogTaps();
        $this->registerFlushOnTermination();
        $this->registerQueueTelemetryListeners();
        $this->registerQueueFlushListeners();
        $this->registerConsoleTelemetryListeners();
        $this->registerSchedulerTelemetryListeners();
    }

    /**
     * Adds TraceCorrelationLogTap (Phase 7A), HttpErrorContextLogTap
     * (Phase 7B.1, Part 6), QueueErrorContextLogTap/
     * ConsoleErrorContextLogTap (Phase 7B.2, Part 9), and
     * SchedulerErrorContextLogTap (Phase 7B.3, Part 10) to every
     * configured log channel without editing config/logging.php —
     * trace_id / span_id, and safe HTTP/queue/console/scheduler context on
     * exception records, then appear in the `extra` bag of every log
     * record for every channel automatically (logs-philosophy.md §6).
     */
    private function registerLogTaps(): void
    {
        $config = $this->app['config'];
        $channels = (array) $config->get('logging.channels', []);
        $taps = [
            TraceCorrelationLogTap::class,
            HttpErrorContextLogTap::class,
            QueueErrorContextLogTap::class,
            ConsoleErrorContextLogTap::class,
            SchedulerErrorContextLogTap::class,
        ];

        foreach (array_keys($channels) as $channel) {
            $tap = (array) $config->get("logging.channels.{$channel}.tap", []);

            foreach ($taps as $tapClass) {
                if (! in_array($tapClass, $tap, true)) {
                    $tap[] = $tapClass;
                }
            }

            $config->set("logging.channels.{$channel}.tap", $tap);
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

    /**
     * Phase 7B.2, Part 7 — the minimum Laravel queue events that can
     * express success/failed/released/retried/timed_out without
     * double-counting a single job attempt, plus JobExceptionOccurred for
     * log correlation only (see QueueExecutionTelemetry's docblock).
     * Registered directly on the container's event dispatcher rather than
     * via config/EventServiceProvider — this module owns its own wiring,
     * exactly like HttpTelemetryMiddleware is wired in bootstrap/app.php
     * rather than a route middleware group.
     */
    private function registerQueueTelemetryListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $events->listen(JobProcessing::class, [QueueExecutionTelemetry::class, 'jobProcessing']);
        $events->listen(JobProcessed::class, [QueueExecutionTelemetry::class, 'jobProcessed']);
        $events->listen(JobExceptionOccurred::class, [QueueExecutionTelemetry::class, 'jobExceptionOccurred']);
        $events->listen(JobFailed::class, [QueueExecutionTelemetry::class, 'jobFailed']);
        $events->listen(JobReleasedAfterException::class, [QueueExecutionTelemetry::class, 'jobReleasedAfterException']);
        $events->listen(JobTimedOut::class, [QueueExecutionTelemetry::class, 'jobTimedOut']);
    }

    /**
     * TD-6 (observability-foundation, discovered while validating Phase
     * 7B.5 live in staging): registerFlushOnTermination()'s `terminating`
     * hook — the *only* place this codebase ever called
     * TelemetryManager::flush() before this phase — never fires for a
     * `queue:work` daemon. Confirmed by reading
     * Illuminate\Queue\Worker::kill() (vendor/laravel/framework/src/
     * Illuminate/Queue/Worker.php): on every graceful shutdown signal
     * (SIGTERM/SIGQUIT/SIGINT, including every redeploy) it dispatches a
     * WorkerStopping event nothing here listened to, then calls
     * `posix_kill(getmypid(), SIGKILL)` — an uncatchable signal that kills
     * the process before Laravel's `app()->terminate()` (and therefore
     * every `terminating` callback) or even the OTel SDK's own
     * register_shutdown_function-based flush ever runs. Reproduced locally
     * end-to-end: real queue:work daemon, real jobs, real OTel Collector —
     * metrics and traces recorded during job processing never left the
     * process, under normal operation *or* graceful shutdown; only logs
     * did, because the "otel" Monolog channel exports synchronously per
     * log call rather than through this batched flush path. This affects
     * every metric/span recorded from *any* queue job platform-wide —
     * ixora_queue_job_total/duration (D-04), ixora_smart_home_dispatch_
     * total, ixora_smart_home_action_total/duration (D-02), and
     * ixora_push_delivery_total (D-03, Phase 7B.5) alike — not something
     * specific to Push.
     *
     * Fix: flush after every job (JobProcessed/JobFailed/
     * JobExceptionOccurred/JobTimedOut — the same event set
     * QueueExecutionTelemetry already listens to) instead of relying on
     * process termination at all. WorkerStopping is also flushed as a
     * supplementary safety net — it dispatches synchronously *before*
     * Worker::kill()'s SIGKILL call, so a listener registered here does
     * get a chance to run, unlike anything after that point.
     */
    private function registerQueueFlushListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $flush = function () {
            try {
                $this->app->make(TelemetryManager::class)->flush();
            } catch (Throwable) {
                // Best-effort only — a flush failure must never affect job
                // processing, retries, or worker shutdown.
            }
        };

        $events->listen(JobProcessed::class, $flush);
        $events->listen(JobFailed::class, $flush);
        $events->listen(JobExceptionOccurred::class, $flush);
        $events->listen(JobTimedOut::class, $flush);
        $events->listen(WorkerStopping::class, $flush);
    }

    /**
     * Phase 7B.2, Part 7 — CommandStarting/CommandFinished, the Laravel
     * events the spec prefers. See ConsoleCommandTelemetry's docblock for
     * the documented, verified limitation: Laravel does not dispatch these
     * during `APP_ENV=testing` (back_vibes's own test environment).
     */
    private function registerConsoleTelemetryListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $events->listen(CommandStarting::class, [ConsoleCommandTelemetry::class, 'commandStarting']);
        $events->listen(CommandFinished::class, [ConsoleCommandTelemetry::class, 'commandFinished']);
    }

    /**
     * Phase 7B.3, Part 7 — the full set of Illuminate\Console\Scheduling
     * lifecycle events this Laravel version dispatches (verified in
     * Illuminate\Console\Scheduling\ScheduleRunCommand/ScheduleFinishCommand
     * — see SchedulerExecutionTelemetry's docblock for exactly how each one
     * is used). Registered directly on the container's event dispatcher,
     * exactly like the Queue/Console listeners above — this module owns
     * its own wiring.
     */
    private function registerSchedulerTelemetryListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $events->listen(ScheduledTaskStarting::class, [SchedulerExecutionTelemetry::class, 'scheduledTaskStarting']);
        $events->listen(ScheduledTaskFinished::class, [SchedulerExecutionTelemetry::class, 'scheduledTaskFinished']);
        $events->listen(ScheduledTaskFailed::class, [SchedulerExecutionTelemetry::class, 'scheduledTaskFailed']);
        $events->listen(ScheduledTaskSkipped::class, [SchedulerExecutionTelemetry::class, 'scheduledTaskSkipped']);
        $events->listen(ScheduledBackgroundTaskFinished::class, [SchedulerExecutionTelemetry::class, 'scheduledBackgroundTaskFinished']);
    }
}
