<?php

declare(strict_types=1);

namespace App\SmartHome\Adapters;

use App\SmartHome\Canonical\Access;
use App\SmartHome\Canonical\BooleanConstraint;
use App\SmartHome\Canonical\Capability;
use App\SmartHome\Canonical\CapabilityCatalog;
use App\SmartHome\Canonical\CapabilityContract;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\CommandValidator;
use App\SmartHome\Canonical\Operation;

/**
 * Translates between Home Assistant's conventions and the canonical model
 * (ADR-037 §12, CSDM-03).
 *
 * This class is where Home Assistant's vocabulary is allowed to exist and
 * where it stops. Inside it: domains, `supported_features` bitmasks, service
 * names, the 0-255 brightness scale. Outside it: capability ids, operations,
 * constraints and percentages. Everything the ADR calls a protocol artifact
 * dies at this boundary, converted exactly once on write and once on read.
 *
 * `toggle` deserves a note, because it is the case GH04 already hit from the
 * other side: Home Assistant happens to expose a native `toggle` service, so
 * this mapper implements the canonical operation with one call. That is an
 * implementation detail of THIS mapper, not a property of the operation —
 * Google Home's mapper composes the same canonical `toggle` from read-invert-
 * write (CSDM-04). Neither ecosystem's convenience defines the domain.
 */
final class HomeAssistantCanonicalMapper
{
    /**
     * Home Assistant's brightness scale. The one place in the codebase where
     * this number is legitimate: it is a fact about Home Assistant's protocol,
     * used only to convert to and from the canonical percentage, and it never
     * travels past this class. The boundary guard forbids it everywhere else.
     */
    private const HA_BRIGHTNESS_MAX = 255;

    /** Canonical operation → Home Assistant service, for the light/switch/fan domains. */
    private const OPERATION_SERVICE_MAP = [
        'on' => 'turn_on',
        'off' => 'turn_off',
        'toggle' => 'toggle',
        // Home Assistant sets brightness through turn_on with a payload, rather
        // than through a service of its own.
        'set' => 'turn_on',
    ];

    /**
     * The canonical capabilities a Home Assistant entity offers.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<Capability>
     */
    public function capabilitiesFor(string $domain, array $attributes, bool $supportsBrightness): array
    {
        $capabilities = match ($domain) {
            'light', 'switch', 'fan' => [
                new Capability(
                    CapabilityId::Power,
                    Access::ReadWrite,
                    [Operation::On, Operation::Off, Operation::Toggle],
                    new BooleanConstraint,
                ),
            ],
            // A media player can be powered but not toggled through this path —
            // the canonical capability carries only the operations the provider
            // genuinely offers, rather than a uniform set per capability id.
            'media_player' => [
                new Capability(
                    CapabilityId::Power,
                    Access::ReadWrite,
                    [Operation::On, Operation::Off],
                    new BooleanConstraint,
                ),
            ],
            default => [],
        };

        if ($domain === 'light' && $supportsBrightness) {
            // The constraint is the canonical 0-100 percent range, NOT Home
            // Assistant's scale. The device's real resolution is a protocol
            // detail this mapper converts, not a domain fact it reports.
            $capabilities[] = Capability::fromCatalog(
                CapabilityId::Brightness,
                CapabilityCatalog::canonicalBrightnessConstraint(),
            );
        }

        return $capabilities;
    }

    /**
     * The stored `devices.capabilities` payload during the transition window
     * (ADR-037 §8).
     *
     * Deliberately BOTH shapes: the canonical envelope, and the legacy
     * `can_*` keys beside it. Expand/contract, not a flip.
     *
     * Emitting canonical alone would be silently breaking. `front_vibes`
     * decides which actions its editor offers by checking for `can_turn_on`
     * and friends (`utils/device-action.ts`), so a device that re-synced would
     * offer the user no actions at all — the failure would look like a data
     * problem, not like a deployment that got ahead of its consumer. The
     * backend's own `ActionType::isBlockedByDeviceCapabilities` reads the same
     * keys. CSDM-06 moves the frontend onto the canonical envelope; CSDM-07
     * removes this second half. Until then, both readers see what they expect,
     * and the version marker tells anyone who asks which is authoritative.
     *
     * @param  list<Capability>  $capabilities
     * @return array<string, mixed>
     */
    public function toStoredPayload(array $capabilities): array
    {
        $payload = CapabilityContract::envelope($capabilities);

        foreach ($capabilities as $capability) {
            foreach ($this->legacyKeysFor($capability) as $key => $value) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * Converts a canonical command into the Home Assistant service and payload
     * that performs it.
     *
     * @param  array<string, mixed>  $parameters  canonical parameters
     * @return array{service: string, payload: array<string, mixed>}
     */
    public function toServiceCall(
        CapabilityId $capabilityId,
        Operation $operation,
        array $parameters = [],
    ): array {
        $service = self::OPERATION_SERVICE_MAP[$operation->value] ?? 'turn_on';

        if ($capabilityId !== CapabilityId::Brightness || $operation !== Operation::Set) {
            return ['service' => $service, 'payload' => []];
        }

        $value = $parameters[CommandValidator::VALUE_KEY] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            return ['service' => $service, 'payload' => []];
        }

        return [
            'service' => $service,
            'payload' => ['brightness' => $this->percentToProviderScale((float) $value)],
        ];
    }

    /** Canonical percent (0-100) → Home Assistant's native scale. */
    public function percentToProviderScale(float $percent): int
    {
        return (int) round($percent / 100 * self::HA_BRIGHTNESS_MAX);
    }

    /** Home Assistant's native scale → canonical percent (0-100). */
    public function providerScaleToPercent(float $raw): int
    {
        return (int) round($raw / self::HA_BRIGHTNESS_MAX * 100);
    }

    /** Home Assistant's `state` string → the canonical boolean power value. */
    public function stateToPower(?string $state): ?bool
    {
        return match ($state) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }

    /**
     * The legacy ADR-033 keys equivalent to a canonical capability, for the
     * transitional dual payload above. Removed by CSDM-07.
     *
     * @return array<string, array<string, mixed>>
     */
    private function legacyKeysFor(Capability $capability): array
    {
        if ($capability->id === CapabilityId::Brightness) {
            return [
                'can_set_brightness' => [
                    'min' => 0,
                    'max' => self::HA_BRIGHTNESS_MAX,
                    'step' => 1,
                ],
            ];
        }

        if ($capability->id !== CapabilityId::Power) {
            return [];
        }

        $keys = [];

        foreach ($capability->operations as $operation) {
            $key = match ($operation) {
                Operation::On => 'can_turn_on',
                Operation::Off => 'can_turn_off',
                Operation::Toggle => 'can_toggle',
                default => null,
            };

            if ($key !== null) {
                $keys[$key] = [];
            }
        }

        return $keys;
    }
}
