<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\SmartHome\ProviderAdapterRegistry;
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
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('provider_connections', 'name')
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id)),
            ],
            'provider' => [
                'required',
                Rule::in(app(ProviderAdapterRegistry::class)->registeredSlugs()),
            ],
            'config' => ['required', 'array'],
            'config.base_url' => ['required', 'url:https'],
            'encrypted_credentials' => ['required', 'array'],
            'encrypted_credentials.access_token' => ['required', 'string'],
            'status' => ['prohibited'],
            'last_tested_at' => ['prohibited'],
        ];
    }
}
