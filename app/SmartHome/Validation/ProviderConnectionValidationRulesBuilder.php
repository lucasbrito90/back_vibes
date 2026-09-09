<?php

declare(strict_types=1);

namespace App\SmartHome\Validation;

use App\SmartHome\DTOs\ProviderDescriptor;
use App\SmartHome\DTOs\ProviderFieldSchema;
use App\SmartHome\ProviderDescriptorRegistry;

/**
 * Builds Laravel validation rules for provider connection config and credentials
 * from {@see ProviderDescriptorRegistry} (ADR-032 / T09 descriptors).
 */
final class ProviderConnectionValidationRulesBuilder
{
    public function __construct(
        private readonly ProviderDescriptorRegistry $descriptorRegistry,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public function storeProviderFieldRules(string $provider): array
    {
        return $this->providerFieldRules(
            $this->descriptorRegistry->forSlug($provider),
            isUpdate: false,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public function updateProviderFieldRules(string $provider): array
    {
        return $this->providerFieldRules(
            $this->descriptorRegistry->forSlug($provider),
            isUpdate: true,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function providerFieldRules(ProviderDescriptor $descriptor, bool $isUpdate): array
    {
        return array_merge(
            $this->configRules($descriptor, $isUpdate),
            $this->credentialRules($descriptor, $isUpdate),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function configRules(ProviderDescriptor $descriptor, bool $isUpdate): array
    {
        // Container-level rule: 'present' (not 'required') — the key must exist
        // in the payload (protects against total omission), but an empty array
        // is a valid value for providers whose descriptor declares no fields
        // (e.g. google_home). Per-field enforcement happens below via
        // fieldRules(), which still uses 'required' where the descriptor says so.
        $rules = [
            'config' => [$isUpdate ? 'sometimes' : 'present', 'array'],
        ];

        foreach ($descriptor->config as $key => $schema) {
            $rules['config.'.$key] = $this->fieldRules($schema, $descriptor->slug, $isUpdate);
        }

        return $rules;
    }

    /**
     * @return array<string, list<string>>
     */
    private function credentialRules(ProviderDescriptor $descriptor, bool $isUpdate): array
    {
        // Same 'present' vs 'required' reasoning as configRules() above — a
        // provider with no server-side credential (ADR-036 Decision 4, e.g.
        // google_home) must be able to send an empty encrypted_credentials
        // object without being rejected for "field is required".
        $rules = [
            'encrypted_credentials' => [$isUpdate ? 'sometimes' : 'present', 'array'],
        ];

        foreach ($descriptor->credentials as $key => $schema) {
            $rules['encrypted_credentials.'.$key] = $this->fieldRules($schema, $descriptor->slug, $isUpdate);
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function fieldRules(ProviderFieldSchema $schema, string $providerSlug, bool $isUpdate): array
    {
        $rules = [];

        if ($isUpdate) {
            $rules[] = 'sometimes';
        } elseif ($schema->required) {
            $rules[] = 'required';
        }

        $rules[] = match ($schema->type) {
            'string' => 'string',
            default => 'string',
        };

        if ($schema->format !== null) {
            $rules[] = $this->formatRule($schema->format, $providerSlug);
        }

        return $rules;
    }

    private function formatRule(string $format, string $providerSlug): string
    {
        if ($format === 'url:https') {
            $allowHttp = (bool) config('smart_home.providers.'.$providerSlug.'.allow_http', false);

            return $allowHttp ? 'url' : 'url:https';
        }

        return $format;
    }
}
