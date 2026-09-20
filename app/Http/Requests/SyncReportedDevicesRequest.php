<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\SmartHome\ActionType;
use App\SmartHome\Canonical\Capability;
use App\SmartHome\Canonical\CapabilityContract;
use App\SmartHome\Canonical\Exceptions\InvalidCapabilityDefinitionException;
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

                // CSDM-04: a client that speaks the canonical contract sends the
                // envelope — and, during the transition window, the legacy keys
                // beside it (ADR-037 §8). Before this, the vocabulary check
                // rejected `contract_version` and `capabilities` as unknown
                // ADR-033 keys, which 422'd every Google device import the
                // moment its mapper went canonical.
                $isCanonical = CapabilityContract::isCanonicalEnvelope($capabilities);

                if ($isCanonical) {
                    $this->validateCanonicalEnvelope($validator, $index, $capabilities);
                }

                foreach (array_keys($capabilities) as $capabilityKey) {
                    if (! is_string($capabilityKey)) {
                        continue;
                    }

                    // The two envelope keys are validated above, as a contract
                    // rather than as vocabulary entries.
                    if ($isCanonical && in_array($capabilityKey, [CapabilityContract::VERSION_KEY, 'capabilities'], true)) {
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
     * Validates the canonical half of a reported capability payload
     * (ADR-037 §2-§5, CSDM-04).
     *
     * A client is not trusted to send a well-formed contract just because it
     * claims a version: every entry is rebuilt through the same validating
     * value object the rest of the domain uses, so a malformed capability is
     * refused here rather than persisted and discovered later at dispatch.
     *
     * A major version this server does not understand is refused outright. The
     * alternative — storing it and hoping — is how a transition window turns
     * into corrupted data.
     *
     * @param  array<string, mixed>  $capabilities
     */
    private function validateCanonicalEnvelope(Validator $validator, int|string $index, array $capabilities): void
    {
        $declared = $capabilities[CapabilityContract::VERSION_KEY] ?? null;
        $major = is_string($declared) ? CapabilityContract::majorVersionOf($declared) : null;

        if ($major !== CapabilityContract::majorVersionOf(CapabilityContract::VERSION)) {
            $validator->errors()->add(
                "devices.$index.capabilities.".CapabilityContract::VERSION_KEY,
                'The reported capability contract version is not supported by this server.',
            );

            return;
        }

        $entries = $capabilities['capabilities'] ?? null;

        if (! is_array($entries)) {
            $validator->errors()->add(
                "devices.$index.capabilities.capabilities",
                'A canonical capability envelope must carry a capabilities map.',
            );

            return;
        }

        foreach ($entries as $capabilityId => $entry) {
            if (! is_array($entry)) {
                $validator->errors()->add(
                    "devices.$index.capabilities.capabilities.$capabilityId",
                    'Each canonical capability must be an object.',
                );

                continue;
            }

            try {
                Capability::fromArray($entry);
            } catch (InvalidCapabilityDefinitionException $e) {
                $validator->errors()->add(
                    "devices.$index.capabilities.capabilities.$capabilityId",
                    $e->getMessage(),
                );
            }
        }
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
