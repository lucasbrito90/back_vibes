<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Device;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\CommandRejectionReason;
use App\SmartHome\Canonical\CommandValidator;
use App\SmartHome\Canonical\Operation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a direct canonical capability command (DEV-02).
 *
 * Structural validation (capability_id in CapabilityId, operation in Operation)
 * runs first. The after() hook then validates the command against the device's
 * own declared capabilities via CommandValidator — same gate the dispatch path
 * uses, now at the write boundary instead of job execution time.
 *
 * Ownership is enforced by DeviceController::commands() via DevicePolicy, not
 * here — the FormRequest receives the route-bound Device only for capability
 * validation, not as an authorization step.
 */
class ExecuteDeviceCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'capability_id' => [
                'required',
                'string',
                Rule::in(array_column(CapabilityId::cases(), 'value')),
            ],
            'operation' => [
                'required',
                'string',
                Rule::in(array_column(Operation::cases(), 'value')),
            ],
            'parameters' => ['nullable', 'array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateCanonicalCommand($validator);
            },
        ];
    }

    private function validateCanonicalCommand(Validator $validator): void
    {
        /** @var Device|null $device */
        $device = $this->route('device');

        if (! $device instanceof Device) {
            return;
        }

        $capabilityId = CapabilityId::tryFrom((string) $this->input('capability_id'));
        $operation = Operation::tryFrom((string) $this->input('operation'));

        if ($capabilityId === null || $operation === null) {
            return;
        }

        $result = app(CommandValidator::class)->validate(
            $device->capabilities,
            $capabilityId,
            $operation,
            $this->parametersForValidation(),
        );

        if ($result->wasRejected()) {
            $field = match ($result->reason) {
                default => 'capability_id',
                CommandRejectionReason::ParameterOutOfRange,
                CommandRejectionReason::ParameterMalformed => 'parameters',
            };

            $validator->errors()->add($field, (string) $result->message);
        }
    }

    /** @return array<string, mixed> */
    private function parametersForValidation(): array
    {
        $parameters = $this->input('parameters');

        return is_array($parameters) ? $parameters : [];
    }
}
