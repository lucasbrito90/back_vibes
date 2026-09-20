<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * Reads ADR-033-shaped capability maps as canonical capabilities (ADR-037 §8).
 *
 * READ ONLY, and deliberately so. Capabilities are already ephemeral — every
 * provider sync recomputes them — so ADR-037 requires no destructive backfill:
 * a device's row is naturally replaced in the canonical shape the next time it
 * syncs under a mapper updated by CSDM-03/CSDM-04. This class exists to keep
 * rows written before that moment readable, nothing more. Nothing here writes.
 *
 * The legacy shape is a flat map where the key doubled as the operation:
 *
 *     {"can_turn_on": {}, "can_turn_off": {}, "can_toggle": {},
 *      "can_set_brightness": {"min": 0, "max": 255, "step": 1}}
 *
 * Two things are reconstructed from it. First, the three `can_turn_*`/`can_toggle`
 * keys collapse into the single canonical `power` capability whose operations
 * they always were. Second — the one real conversion — legacy brightness bounds
 * are Home Assistant's 0-255 leaking into what was supposed to be domain data;
 * they are replaced by the canonical 0-100 percent range, never carried forward.
 *
 * ADR-033 §5's fail-open policy is preserved verbatim: an unknown key, a
 * malformed constraint or a null map yields fewer capabilities, never an
 * exception. A legacy row must never be able to break a read path.
 */
final class LegacyCapabilityMapReader
{
    private const LEGACY_POWER_KEYS = [
        'can_turn_on' => Operation::On,
        'can_turn_off' => Operation::Off,
        'can_toggle' => Operation::Toggle,
    ];

    private const LEGACY_BRIGHTNESS_KEY = 'can_set_brightness';

    /**
     * Converts a legacy capability map into canonical capabilities.
     *
     * @param  array<string, mixed>|null  $legacy
     * @return list<Capability>
     */
    public static function read(?array $legacy): array
    {
        if ($legacy === null || $legacy === []) {
            return [];
        }

        $capabilities = [];

        $powerOperations = self::powerOperationsIn($legacy);

        if ($powerOperations !== []) {
            $capabilities[] = new Capability(
                CapabilityId::Power,
                Access::ReadWrite,
                $powerOperations,
                new BooleanConstraint,
            );
        }

        if (array_key_exists(self::LEGACY_BRIGHTNESS_KEY, $legacy)) {
            // The legacy bounds are discarded on purpose: they are a provider
            // scale (0-255), and the canonical range is fixed by ADR-037 §5.
            $capabilities[] = Capability::fromCatalog(
                CapabilityId::Brightness,
                CapabilityCatalog::canonicalBrightnessConstraint(),
            );
        }

        return $capabilities;
    }

    /**
     * Whether a stored payload is in the legacy shape rather than the canonical
     * envelope — the discriminator callers use during the transition window.
     *
     * @param  array<mixed>|null  $payload
     */
    public static function isLegacyShape(?array $payload): bool
    {
        if ($payload === null || $payload === []) {
            return false;
        }

        return ! CapabilityContract::isCanonicalEnvelope($payload);
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return list<Operation>
     */
    private static function powerOperationsIn(array $legacy): array
    {
        $operations = [];

        foreach (self::LEGACY_POWER_KEYS as $key => $operation) {
            if (array_key_exists($key, $legacy)) {
                $operations[] = $operation;
            }
        }

        return $operations;
    }
}
