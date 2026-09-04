<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\SmartHome\ProviderAdapterRegistry;
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
        $adapterRegistry = app(ProviderAdapterRegistry::class);
        $registeredSlugs = $adapterRegistry->registeredSlugs();

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
