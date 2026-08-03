<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Telemetry configuration
|--------------------------------------------------------------------------
|
| This file is the Laravel-native, testable projection of the same OTEL_*
| environment variables the OpenTelemetry PHP SDK itself reads directly (see
| .env.example §"Observability — OpenTelemetry"). It exists so that
| TelemetryServiceProvider, App\Telemetry\Configuration\TelemetryConfig, and
| the test-suite have one typed, config()-accessible place to read these
| values from — and so the enabled/disabled decision below can be made
| without depending on the OpenTelemetry SDK.
|
| It is NOT what builds the SDK's TracerProvider/MeterProvider — that is
| \OpenTelemetry\SDK\SdkAutoloader (vendor code), triggered by
| OTEL_PHP_AUTOLOAD_ENABLED=true, which reads the raw process environment
| directly and independently of Laravel's config cache (see
| backend-sdk-foundation.md §"SDK bootstrap"). Both read the same
| variables, so in every environment where OTEL_* are real process
| environment variables (not just .env lines) the two stay in sync.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Telemetry enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the Telemetry Abstraction Layer. When false (or when
    | constructing the OpenTelemetry implementation fails for any reason),
    | TelemetryServiceProvider binds NoopTelemetryManager instead of
    | OpenTelemetryManager — the application behaves exactly as if
    | OpenTelemetry did not exist (telemetry-availability-policy.md).
    |
    | OTEL_SDK_DISABLED is the OTel-standard variable name; it also disables
    | the SDK's own globals when read directly by auto-instrumentation.
    |
    */

    'enabled' => ! (bool) env('OTEL_SDK_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Service identity
    |--------------------------------------------------------------------------
    |
    | service_name MUST match one of the official Ixora service names
    | (telemetry-naming-convention.md §3): back_vibes-api or back_vibes-worker.
    | service_version defaults to APP_VERSION (set at deploy time from the
    | release tag) so traces/metrics/logs can be filtered by release.
    |
    */

    'service_name' => env('OTEL_SERVICE_NAME', 'back_vibes-api'),
    'service_version' => env('OTEL_SERVICE_VERSION', env('APP_VERSION', 'unknown')),
    'service_namespace' => env('OTEL_SERVICE_NAMESPACE', 'ixora'),

    /*
    |--------------------------------------------------------------------------
    | Deployment environment
    |--------------------------------------------------------------------------
    |
    | deployment.environment MUST be one of development | staging | production
    | (telemetry-naming-convention.md §4) — never a raw APP_ENV alias such as
    | "local" or "prod". APP_ENV is mapped below; unknown values fall back to
    | "development" so a misconfigured APP_ENV never exports a forbidden alias.
    |
    */

    'environment' => match (env('APP_ENV', 'production')) {
        'production' => 'production',
        'staging' => 'staging',
        default => 'development',
    },

    /*
    |--------------------------------------------------------------------------
    | OTLP exporter
    |--------------------------------------------------------------------------
    |
    | Points at the OpenTelemetry Collector ONLY — never Prometheus, Loki, or
    | Tempo directly (ADR-028). protocol supports "http/protobuf" (default)
    | or "http/json"; gRPC is intentionally not used here to avoid the
    | ext-grpc PHP extension (see backend-sdk-foundation.md §SDK evaluation).
    |
    | headers uses the OTel-standard "key1=value1,key2=value2" format, e.g.
    | "Authorization=Bearer <OTEL_INGEST_API_KEY_BACKEND>" for the Collector's
    | bearertokenauth extension (collector/config.yaml — otlp/backend).
    |
    | timeout_ms caps every export attempt so a slow/unreachable Collector
    | can never add latency to a request, job, or command
    | (telemetry-availability-policy.md R3 — bounded, never unbounded).
    |
    */

    'otlp' => [
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', ''),
        'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf'),
        'headers' => env('OTEL_EXPORTER_OTLP_HEADERS', ''),
        'timeout_ms' => (int) env('OTEL_EXPORTER_OTLP_TIMEOUT', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trace sampling
    |--------------------------------------------------------------------------
    |
    | Mirrors the Collector's probabilistic_sampler as a head-sampling
    | decision made at the SDK level too (ADR-031 sampling policy). Supports
    | the standard OTel sampler names: always_on, always_off, traceidratio,
    | parentbased_always_on, parentbased_traceidratio (default).
    |
    | sampler_arg is the sample ratio (0.0–1.0) for the *_traceidratio samplers
    | — 0.10 matches the Collector's OTEL_TRACE_SAMPLE_RATE_SUCCESS default.
    |
    */

    'traces' => [
        'sampler' => env('OTEL_TRACES_SAMPLER', 'parentbased_traceidratio'),
        'sampler_arg' => (float) env('OTEL_TRACES_SAMPLER_ARG', 0.10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra resource attributes
    |--------------------------------------------------------------------------
    |
    | Raw OTEL_RESOURCE_ATTRIBUTES passthrough ("key1=value1,key2=value2").
    | Use only for attributes not already covered above — service.name,
    | service.version, service.namespace, and deployment.environment are
    | always set from the typed values above and take precedence.
    |
    */

    'resource_attributes' => env('OTEL_RESOURCE_ATTRIBUTES', ''),

    /*
    |--------------------------------------------------------------------------
    | Zero-code SDK bootstrap + auto instrumentation (informational)
    |--------------------------------------------------------------------------
    |
    | Mirrors OTEL_PHP_AUTOLOAD_ENABLED / OTEL_PHP_DISABLED_INSTRUMENTATIONS
    | purely so tests and diagnostics can assert on them through config().
    | Changing these values here has NO runtime effect — both are read
    | directly from the process environment by vendor code (SdkAutoloader
    | and each opentelemetry-auto-* package's _register.php) before Laravel
    | boots. See .env.example for the real toggle.
    |
    */

    'autoload_enabled' => (bool) env('OTEL_PHP_AUTOLOAD_ENABLED', false),
    'disabled_instrumentations' => env('OTEL_PHP_DISABLED_INSTRUMENTATIONS', ''),

    /*
    |--------------------------------------------------------------------------
    | Exporter configuration (informational)
    |--------------------------------------------------------------------------
    |
    | These mirror the standard OTEL_*_EXPORTER env vars purely so tests and
    | diagnostics can assert on them through config(). Changing them here has
    | NO effect on the SDK export path — the SDK reads raw process env vars
    | directly and independently of Laravel's config cache. The safe default
    | for local development is "none" so no network connections are attempted.
    |
    */

    'traces_exporter' => env('OTEL_TRACES_EXPORTER', 'otlp'),
    'metrics_exporter' => env('OTEL_METRICS_EXPORTER', 'otlp'),
    'logs_exporter' => env('OTEL_LOGS_EXPORTER', 'none'),

];
