<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\SmartHome\ProviderType;
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
            'name' => ['sometimes', 'string', 'max:255'],
            'provider' => [
                'sometimes',
                Rule::in(array_map(fn (ProviderType $t) => $t->value, ProviderType::mvpAllowed())),
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
