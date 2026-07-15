<?php

declare(strict_types=1);

namespace App\Telemetry\Resources;

use App\Telemetry\Configuration\TelemetryConfig;

/**
 * Vendor-neutral snapshot of the OTel resource attributes Ixora exports for
 * every signal (ADR-029 §resource attributes; telemetry-naming-convention.md §3–4).
 *
 * This is a plain value object — it never imports the OpenTelemetry SDK.
 * It documents and is asserted against in tests to confirm that
 * config('telemetry') maps to the exact resource attribute set the
 * OpenTelemetry SDK's own ResourceInfoFactory::defaultResource() builds from
 * the equivalent raw OTEL_* environment variables at bootstrap time (the SDK
 * builds the actual SDK ResourceInfo — see backend-sdk-foundation.md
 * §"SDK bootstrap"; this class never does).
 */
final class ResourceAttributes
{
    /**
     * @param  array<string, string>  $extra  Additional attributes merged from
     *                                        OTEL_RESOURCE_ATTRIBUTES (raw key=value pairs).
     */
    public function __construct(
        public readonly string $serviceName,
        public readonly string $serviceVersion,
        public readonly string $serviceNamespace,
        public readonly string $deploymentEnvironment,
        public readonly array $extra = [],
    ) {}

    public static function fromConfig(TelemetryConfig $config): self
    {
        return new self(
            serviceName: $config->serviceName,
            serviceVersion: $config->serviceVersion,
            serviceNamespace: $config->serviceNamespace,
            deploymentEnvironment: $config->environment,
            extra: $config->resourceAttributes,
        );
    }

    /**
     * Semantic-convention attribute names mapped to values. Keys match the
     * official OTel resource semantic conventions — the same strings the
     * open-telemetry/sem-conv package exposes as constants, kept literal
     * here so this class stays free of any OpenTelemetry dependency.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_merge(
            [
                'service.name' => $this->serviceName,
                'service.version' => $this->serviceVersion,
                'service.namespace' => $this->serviceNamespace,
                'deployment.environment' => $this->deploymentEnvironment,
            ],
            $this->extra,
        );
    }
}
