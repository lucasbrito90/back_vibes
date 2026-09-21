<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Device;
use App\Models\SceneAction;
use App\SmartHome\ActionType;
use App\SmartHome\Canonical\CommandValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSceneActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => [
                'sometimes',
                'integer',
                Rule::exists('devices', 'id'),
            ],
            'action_type' => [
                'sometimes',
                'string',
                Rule::in($this->allowedActionTypes()),
            ],
            'parameters' => ['sometimes', 'nullable', 'array'],
            'delay_seconds' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
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
        /** @var SceneAction|null $existing */
        $existing = $this->route('action');

        $deviceId = $this->input('device_id') ?? $existing?->device_id;
        $actionType = $this->input('action_type') ?? $existing?->action_type;

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

        // CSDM-02 (ADR-037 §7). A partial update must be validated against the
        // resulting action, not the submitted fragment: changing only
        // `parameters` still has to satisfy the stored action_type's
        // constraint, and changing only `action_type` has to satisfy the
        // stored parameters.
        $result = app(CommandValidator::class)->validateLegacyAction(
            $device->capabilities,
            (string) $actionType,
            $this->parametersForValidation($existing),
        );

        if ($result->wasRejected()) {
            $validator->errors()->add('parameters', (string) $result->message);
        }
    }

    /** @return array<string, mixed> */
    private function parametersForValidation(?SceneAction $existing): array
    {
        $parameters = $this->has('parameters')
            ? $this->input('parameters')
            : $existing?->parameters;

        return is_array($parameters) ? $parameters : [];
    }

    private function validateDeviceOwnership(Validator $validator): void
    {
        if (! $this->has('device_id')) {
            return;
        }

        $deviceId = $this->input('device_id');

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
