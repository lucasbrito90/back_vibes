<?php

declare(strict_types=1);

namespace App\Telemetry\Configuration;

/**
 * Typed, immutable view over config('telemetry') — itself sourced entirely
 * from environment variables (config/telemetry.php). Nothing in this class
 * imports the OpenTelemetry SDK; it is the neutral boundary between Laravel
 * configuration and App\Telemetry\OpenTelemetry factories.
 */
final class TelemetryConfig
{
    /**
     * @param  array<string, string>  $otlpHeaders
     * @param  array<string, string>  $resourceAttributes  Extra attributes parsed from OTEL_RESOURCE_ATTRIBUTES.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $serviceName,
        public readonly string $serviceVersion,
        public readonly string $serviceNamespace,
        public readonly string $environment,
        public readonly string $otlpEndpoint,
        public readonly string $otlpProtocol,
        public readonly array $otlpHeaders,
        public readonly int $otlpTimeoutMs,
        public readonly string $tracesSampler,
        public readonly float $tracesSamplerArg,
        public readonly array $resourceAttributes,
        public readonly bool $autoloadEnabled,
        public readonly string $disabledInstrumentations,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The array returned by config('telemetry').
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            serviceName: (string) ($config['service_name'] ?? 'back_vibes-api'),
            serviceVersion: (string) ($config['service_version'] ?? 'unknown'),
            serviceNamespace: (string) ($config['service_namespace'] ?? 'ixora'),
            environment: (string) ($config['environment'] ?? 'development'),
            otlpEndpoint: (string) ($config['otlp']['endpoint'] ?? ''),
            otlpProtocol: (string) ($config['otlp']['protocol'] ?? 'http/protobuf'),
            otlpHeaders: self::parseKeyValueList((string) ($config['otlp']['headers'] ?? '')),
            otlpTimeoutMs: (int) ($config['otlp']['timeout_ms'] ?? 2000),
            tracesSampler: (string) ($config['traces']['sampler'] ?? 'parentbased_traceidratio'),
            tracesSamplerArg: (float) ($config['traces']['sampler_arg'] ?? 0.10),
            resourceAttributes: self::parseKeyValueList((string) ($config['resource_attributes'] ?? '')),
            autoloadEnabled: (bool) ($config['autoload_enabled'] ?? false),
            disabledInstrumentations: (string) ($config['disabled_instrumentations'] ?? ''),
        );
    }

    /**
     * Parses the OTel-standard "key1=value1,key2=value2" format used by both
     * OTEL_RESOURCE_ATTRIBUTES and OTEL_EXPORTER_OTLP_HEADERS.
     *
     * @return array<string, string>
     */
    private static function parseKeyValueList(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $pairs = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);

            if ($entry === '' || ! str_contains($entry, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $entry, 2);
            $key = trim($key);

            if ($key === '') {
                continue;
            }

            $pairs[$key] = trim($value);
        }

        return $pairs;
    }
}
