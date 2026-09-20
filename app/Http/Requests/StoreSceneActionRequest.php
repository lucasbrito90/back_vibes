<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Device;
use App\SmartHome\ActionType;
use App\SmartHome\Canonical\CommandValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSceneActionRequest extends FormRequest
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
                $this->validateActionCapability($validator);
            },
        ];
    }

    private function validateActionCapability(Validator $validator): void
    {
        $deviceId = $this->input('device_id');
        $actionType = $this->input('action_type');

        if ($deviceId === null || $actionType === null) {
            return;
        }

        $device = Device::where('id', $deviceId)
            ->where('user_id', $this->user()->id)
            ->first();

        if ($device === null) {
            return;
        }

        if (ActionType::isBlockedByDeviceCapabilities($device->capabilities, (string) $actionType)) {
            $validator->errors()->add(
                'action_type',
                'The selected action is not supported by this device.'
            );

            return;
        }

        // CSDM-02 (ADR-037 §7): the capability gate above answers "may this
        // device do this at all"; this answers "is the value the caller sent
        // admissible". Without it `parameters` stays free-form JSON all the way
        // into the provider payload — the confirmed brightness: 9999 defect.
        $result = app(CommandValidator::class)->validateLegacyAction(
            $device->capabilities,
            (string) $actionType,
            $this->parametersForValidation(),
        );

        if ($result->wasRejected()) {
            $validator->errors()->add('parameters', (string) $result->message);
        }
    }

    /** @return array<string, mixed> */
    private function parametersForValidation(): array
    {
        $parameters = $this->input('parameters');

        return is_array($parameters) ? $parameters : [];
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
