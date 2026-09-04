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
        $rules = [
            'config' => [$isUpdate ? 'sometimes' : 'required', 'array'],
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
        $rules = [
            'encrypted_credentials' => [$isUpdate ? 'sometimes' : 'required', 'array'],
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
