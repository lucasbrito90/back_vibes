<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\SmartHome\ProviderDescriptorRegistry;
use App\SmartHome\Validation\ProviderConnectionValidationRulesBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProviderConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Provider IDENTITY (ADR-036 Decision 3) — known_providers is a superset
        // of ProviderAdapterRegistry's server-side adapter slugs. A known
        // provider (e.g. google_home) may have no ProviderAdapter at all, so
        // eligibility for ProviderConnection creation must come from here, not
        // from adapter registration.
        $descriptorRegistry = app(ProviderDescriptorRegistry::class);
        $registeredSlugs = $descriptorRegistry->knownSlugs();

        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('provider_connections', 'name')
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id)),
            ],
            'provider' => [
                'required',
                Rule::in($registeredSlugs),
            ],
            'status' => ['prohibited'],
            'last_tested_at' => ['prohibited'],
        ];

        $provider = $this->input('provider');

        if (is_string($provider) && in_array($provider, $registeredSlugs, true)) {
            return array_merge(
                $rules,
                app(ProviderConnectionValidationRulesBuilder::class)->storeProviderFieldRules($provider),
            );
        }

        return array_merge($rules, [
            'config' => ['required', 'array'],
            'encrypted_credentials' => ['required', 'array'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.in' => 'The selected smart home provider is not registered.',
        ];
    }
}
