<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\SmartHome\ProviderAdapterRegistry;
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
        return [
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
                Rule::in(app(ProviderAdapterRegistry::class)->registeredSlugs()),
            ],
            'config' => ['sometimes', 'array'],
            'config.base_url' => ['sometimes', 'url:https'],
            'encrypted_credentials' => ['sometimes', 'array'],
            'encrypted_credentials.access_token' => ['sometimes', 'string'],
            'status' => ['prohibited'],
            'last_tested_at' => ['prohibited'],
        ];
    }
}
