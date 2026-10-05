<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

use App\SmartHome\ProviderConnectionMethod;
use App\SmartHome\ProviderExecutionCapability;
use InvalidArgumentException;

/**
 * Static metadata for a registered Smart Home provider — slug, display label,
 * expected config / credential field shapes, declared connection methods
 * (ADR-045 Decision 2), and declared execution capabilities (ADR-036
 * Decision 2). Immutable.
 *
 * Credential fields describe keys inside the request body's
 * `encrypted_credentials` object; values are never included here.
 *
 * connection_methods is the closed vocabulary of how a provider connection is
 * established (ADR-045 Decision 2). execution_capabilities is a provider-level
 * fact (what the PROVIDER can do at the execution layer), orthogonal to
 * device-level capabilities (ADR-033's `can_*` vocabulary) — the two are
 * never merged or cross-validated.
 */
final readonly class ProviderDescriptor
{
    /**
     * @param  array<string, ProviderFieldSchema>  $config
     * @param  array<string, ProviderFieldSchema>  $credentials
     * @param  list<ProviderConnectionMethod>  $connectionMethods
     * @param  list<ProviderExecutionCapability>  $executionCapabilities
     */
    public function __construct(
        public string $slug,
        public string $label,
        public array $config,
        public array $credentials,
        public array $connectionMethods,
        public array $executionCapabilities,
    ) {}

    /**
     * @param  array{
     *     label: string,
     *     config: array<string, array{type: string, required?: bool, format?: string|null}>,
     *     credentials: array<string, array{type: string, required?: bool, format?: string|null}>,
     *     connection_methods: list<string>,
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
            connectionMethods: self::mapConnectionMethods($slug, $config['connection_methods']),
            executionCapabilities: self::mapExecutionCapabilities($slug, $config['execution_capabilities']),
        );
    }

    /**
     * @param  list<string>  $values
     * @return list<ProviderConnectionMethod>
     */
    private static function mapConnectionMethods(string $slug, array $values): array
    {
        return array_map(
            function (string $value) use ($slug): ProviderConnectionMethod {
                $method = ProviderConnectionMethod::tryFrom($value);

                if ($method === null) {
                    throw new InvalidArgumentException(
                        'Provider descriptor for ['.$slug.'] declares unknown connection method ['.$value.'].'
                    );
                }

                return $method;
            },
            $values,
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
