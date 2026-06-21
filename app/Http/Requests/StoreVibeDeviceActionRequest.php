<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Device;
use App\SmartHome\ActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVibeDeviceActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => [
                'required',
                'integer',
                Rule::exists('devices', 'id'),
            ],
            'action_type' => [
                'required',
                'string',
                Rule::in($this->allowedActionTypes()),
            ],
            'parameters' => ['nullable', 'array'],
            'delay_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateDeviceOwnership($validator);
            },
        ];
    }

    private function validateDeviceOwnership(Validator $validator): void
    {
        $deviceId = $this->input('device_id');

        if ($deviceId === null) {
            return;
        }

        $owned = Device::where('id', $deviceId)
            ->where('user_id', $this->user()->id)
            ->exists();

        if (! $owned) {
            $validator->errors()->add(
                'device_id',
                'The selected device does not belong to you.'
            );
        }
    }

    /** @return array<int, string> */
    private function allowedActionTypes(): array
    {
        return array_map(
            static fn (ActionType $type): string => $type->value,
            ActionType::mvpAllowed()
        );
    }
}
