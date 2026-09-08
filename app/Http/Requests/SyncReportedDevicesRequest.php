<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\SmartHome\ActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Client-reported device catalog (ADR-036 Decision 7).
 *
 * The mobile runtime discovers devices locally and pushes them here; the backend
 * validates shape and ADR-033 capability keys only — persistence is P05.
 */
class SyncReportedDevicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $devices = $this->input('devices', []);
            if (! is_array($devices)) {
                return;
            }

            $allowedKeys = self::allowedCapabilityKeys();

            foreach ($devices as $index => $device) {
                if (! is_array($device)) {
                    continue;
                }

                $capabilities = $device['capabilities'] ?? null;
                if ($capabilities === null) {
                    continue;
                }

                if (! is_array($capabilities)) {
                    continue;
                }

                foreach (array_keys($capabilities) as $capabilityKey) {
                    if (! is_string($capabilityKey)) {
                        continue;
                    }

                    if (! in_array($capabilityKey, $allowedKeys, true)) {
                        $validator->errors()->add(
                            "devices.$index.capabilities.$capabilityKey",
                            "devices.$index.capabilities.$capabilityKey does not belong to the ADR-033 capability vocabulary.",
                        );
                    }
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'devices' => ['required', 'array', 'min:1'],
            'devices.*.provider_device_id' => ['required', 'string'],
            'devices.*.name' => ['required', 'string'],
            'devices.*.type' => ['nullable', 'string'],
            'devices.*.capabilities' => ['nullable', 'array'],
        ];
    }

    /**
     * ADR-033 capability keys derived from ActionType — not hardcoded, so new
     * action types automatically extend the allowed vocabulary.
     *
     * @return list<string>
     */
    private static function allowedCapabilityKeys(): array
    {
        return array_map(
            static fn (ActionType $type): string => $type->requiredCapability(),
            ActionType::cases(),
        );
    }
}
