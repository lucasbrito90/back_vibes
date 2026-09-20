<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * Canonical capability vocabulary (ADR-037 §2).
 *
 * Closed and governed: adding a case is a deliberate amendment to a maintained
 * list, never a string a provider mapper invents. The same discipline ADR-033
 * §3 established for its own vocabulary — this ADR changes the SHAPE each entry
 * carries, not the principle that the set is closed.
 *
 * Mirrored by `$defs.capabilityId` in contracts/smart-home/capability.v1.schema.json;
 * CapabilityContractCoherenceTest fails if the two ever drift apart.
 */
enum CapabilityId: string
{
    case Power = 'power';
    case Brightness = 'brightness';
    case Energy = 'energy';
    case CurrentTemperature = 'current_temperature';
    case TargetTemperature = 'target_temperature';
    case HvacMode = 'hvac_mode';
}
