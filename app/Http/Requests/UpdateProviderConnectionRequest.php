<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ProviderConnection;
use App\SmartHome\ProviderAdapterRegistry;
use App\SmartHome\Validation\ProviderConnectionValidationRulesBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProviderConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $adapterRegistry = app(ProviderAdapterRegistry::class);
        $registeredSlugs = $adapterRegistry->registeredSlugs();

        $rules = [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('provider_connections', 'name')
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id))
                    ->ignore($this->route('providerConnection')),
            ],
            'provider' => [
                'sometimes',
                Rule::in($registeredSlugs),
            ],
            'status' => ['prohibited'],
            'last_tested_at' => ['prohibited'],
        ];

        $provider = $this->resolveProviderSlug();

        if ($provider !== null && in_array($provider, $registeredSlugs, true)) {
            return array_merge(
                $rules,
                app(ProviderConnectionValidationRulesBuilder::class)->updateProviderFieldRules($provider),
            );
        }

        return array_merge($rules, [
            'config' => ['sometimes', 'array'],
            'encrypted_credentials' => ['sometimes', 'array'],
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

    private function resolveProviderSlug(): ?string
    {
        $provider = $this->input('provider');

        if (is_string($provider) && $provider !== '') {
            return $provider;
        }

        $connection = $this->route('provider_connection') ?? $this->route('providerConnection');

        if ($connection instanceof ProviderConnection) {
            return $connection->provider;
        }

        return null;
    }
}
