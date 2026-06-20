<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ProviderConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_connection_id' => [
                'sometimes',
                'integer',
                Rule::exists('provider_connections', 'id'),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string'],
            'provider_device_id' => ['sometimes', 'string', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'status' => ['prohibited'],
            'last_seen_at' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateConnectionOwnership($validator);
            },
        ];
    }

    private function validateConnectionOwnership(Validator $validator): void
    {
        if (! $this->has('provider_connection_id')) {
            return;
        }

        $connectionId = $this->input('provider_connection_id');

        if ($connectionId === null) {
            return;
        }

        $owned = ProviderConnection::where('id', $connectionId)
            ->where('user_id', $this->user()->id)
            ->exists();

        if (! $owned) {
            $validator->errors()->add(
                'provider_connection_id',
                'The selected provider connection does not belong to you.'
            );
        }
    }
}
