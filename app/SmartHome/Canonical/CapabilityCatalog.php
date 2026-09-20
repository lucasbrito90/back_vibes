<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * The closed per-capability rules of the v1 contract (ADR-037 §3-§5).
 *
 * The JSON Schema fixes the vocabulary of ids, operations, access levels and
 * constraint shapes. What it cannot express is which of those go together:
 * that `brightness` admits only `set`, that `energy` admits no operation at
 * all, that `hvac_mode` must be an enum. That is this catalog's job, and
 * CapabilityContractCoherenceTest proves the two never drift apart.
 *
 * Concrete bounds are per-device and supplied by each provider mapper (a
 * thermostat's real 5-35°C range), with ONE exception: brightness is fixed at
 * 0-100 percent by ADR-037 §5 and is not a mapper's choice. Neither 0-255 (Home
 * Assistant) nor 0-254 (Google Home) has a claim to being the domain value —
 * both are protocol artifacts, converted exactly once at the mapper boundary.
 */
final class CapabilityCatalog
{
    /** Canonical brightness range — fixed by ADR-037 §5, not negotiable per device. */
    public const BRIGHTNESS_MIN = 0.0;

    public const BRIGHTNESS_MAX = 100.0;

    public const BRIGHTNESS_STEP = 1.0;

    /**
     * Operations each capability admits. An empty list means the capability is
     * never commanded, only read into DeviceState — `energy` and
     * `current_temperature` are exactly that case, by design (ADR-037 §2).
     *
     * @return list<Operation>
     */
    public static function operationsFor(CapabilityId $id): array
    {
        return match ($id) {
            CapabilityId::Power => [Operation::On, Operation::Off, Operation::Toggle],
            CapabilityId::Brightness,
            CapabilityId::TargetTemperature,
            CapabilityId::HvacMode => [Operation::Set],
            CapabilityId::Energy,
            CapabilityId::CurrentTemperature => [],
        };
    }

    /** The constraint type this capability must carry. No capability in v1 carries null. */
    public static function constraintTypeFor(CapabilityId $id): string
    {
        return match ($id) {
            CapabilityId::Power => 'boolean',
            CapabilityId::HvacMode => 'enum',
            CapabilityId::Brightness,
            CapabilityId::Energy,
            CapabilityId::CurrentTemperature,
            CapabilityId::TargetTemperature => 'number',
        };
    }

    /** The canonical unit for a numeric capability; null for non-numeric ones. */
    public static function unitFor(CapabilityId $id): ?Unit
    {
        return match ($id) {
            CapabilityId::Brightness => Unit::Percent,
            CapabilityId::Energy => Unit::KilowattHour,
            CapabilityId::CurrentTemperature,
            CapabilityId::TargetTemperature => Unit::Celsius,
            CapabilityId::Power,
            CapabilityId::HvacMode => null,
        };
    }

    /**
     * The access level a capability has unless a mapper narrows it. Read-only
     * capabilities cannot be widened: `energy` is a measurement, and no provider
     * makes it writable by declaring so.
     */
    public static function defaultAccessFor(CapabilityId $id): Access
    {
        return match ($id) {
            CapabilityId::Energy,
            CapabilityId::CurrentTemperature => Access::Read,
            CapabilityId::Power,
            CapabilityId::Brightness,
            CapabilityId::TargetTemperature,
            CapabilityId::HvacMode => Access::ReadWrite,
        };
    }

    /** Whether the capability is a measurement that may never be commanded. */
    public static function isReadOnly(CapabilityId $id): bool
    {
        return self::defaultAccessFor($id) === Access::Read;
    }

    /** The canonical brightness constraint — the one range the contract fixes itself. */
    public static function canonicalBrightnessConstraint(): NumberConstraint
    {
        return new NumberConstraint(
            self::BRIGHTNESS_MIN,
            self::BRIGHTNESS_MAX,
            self::BRIGHTNESS_STEP,
            Unit::Percent,
        );
    }
}
