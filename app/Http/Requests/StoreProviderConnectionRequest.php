<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\SmartHome\ProviderType;
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
            'name' => ['required', 'string', 'max:255'],
            'provider' => [
                'required',
                Rule::in(array_map(fn (ProviderType $t) => $t->value, ProviderType::mvpAllowed())),
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
