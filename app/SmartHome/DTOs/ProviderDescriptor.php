<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

/**
 * Static metadata for a registered Smart Home provider — slug, display label,
 * and the expected config / credential field shapes. Immutable.
 *
 * Credential fields describe keys inside the request body's
 * `encrypted_credentials` object; values are never included here.
 */
final readonly class ProviderDescriptor
{
    /**
     * @param  array<string, ProviderFieldSchema>  $config
     * @param  array<string, ProviderFieldSchema>  $credentials
     */
    public function __construct(
        public string $slug,
        public string $label,
        public array $config,
        public array $credentials,
    ) {}

    /**
     * @param  array{
     *     label: string,
     *     config: array<string, array{type: string, required?: bool, format?: string|null}>,
     *     credentials: array<string, array{type: string, required?: bool, format?: string|null}>
     * }  $config
     */
    public static function fromConfigArray(string $slug, array $config): self
    {
        return new self(
            slug: $slug,
            label: $config['label'],
            config: self::mapFields($config['config']),
            credentials: self::mapFields($config['credentials']),
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
