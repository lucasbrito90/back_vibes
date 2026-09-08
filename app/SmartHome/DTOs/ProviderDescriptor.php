<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

use App\SmartHome\ProviderExecutionCapability;
use InvalidArgumentException;

/**
 * Static metadata for a registered Smart Home provider — slug, display label,
 * expected config / credential field shapes, and declared execution
 * capabilities (ADR-036 Decision 2). Immutable.
 *
 * Credential fields describe keys inside the request body's
 * `encrypted_credentials` object; values are never included here.
 *
 * execution_capabilities is a provider-level fact (what the PROVIDER can do
 * at the execution layer) and is orthogonal to device-level capabilities
 * (ADR-033's `can_*` vocabulary) — the two are never merged or cross-validated.
 */
final readonly class ProviderDescriptor
{
    /**
     * @param  array<string, ProviderFieldSchema>  $config
     * @param  array<string, ProviderFieldSchema>  $credentials
     * @param  list<ProviderExecutionCapability>  $executionCapabilities
     */
    public function __construct(
        public string $slug,
        public string $label,
        public array $config,
        public array $credentials,
        public array $executionCapabilities,
    ) {}

    /**
     * @param  array{
     *     label: string,
     *     config: array<string, array{type: string, required?: bool, format?: string|null}>,
     *     credentials: array<string, array{type: string, required?: bool, format?: string|null}>,
     *     execution_capabilities: list<string>
     * }  $config
     */
    public static function fromConfigArray(string $slug, array $config): self
    {
        return new self(
            slug: $slug,
            label: $config['label'],
            config: self::mapFields($config['config']),
            credentials: self::mapFields($config['credentials']),
            executionCapabilities: self::mapExecutionCapabilities($slug, $config['execution_capabilities']),
        );
    }

    /**
     * @param  list<string>  $values
     * @return list<ProviderExecutionCapability>
     */
    private static function mapExecutionCapabilities(string $slug, array $values): array
    {
        return array_map(
            function (string $value) use ($slug): ProviderExecutionCapability {
                $capability = ProviderExecutionCapability::tryFrom($value);

                if ($capability === null) {
                    throw new InvalidArgumentException(
                        'Provider descriptor for ['.$slug.'] declares unknown execution capability ['.$value.'].'
                    );
                }

                return $capability;
            },
            $values,
        );
    }

    /**
     * @param  array<string, array{type: string, required?: bool, format?: string|null}>  $fields
     * @return array<string, ProviderFieldSchema>
     */
    private static function mapFields(array $fields): array
    {
        $mapped = [];

        foreach ($fields as $key => $field) {
            $mapped[$key] = ProviderFieldSchema::fromConfigArray($field);
        }

        return $mapped;
    }
}
