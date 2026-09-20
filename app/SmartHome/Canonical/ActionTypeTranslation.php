<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

use App\SmartHome\ActionType;

/**
 * Reinterprets the legacy ActionType enum as a canonical (capability, operation)
 * pair (ADR-037 §8, §13).
 *
 * ADR-037 supersedes `ActionType` — a fixed enum of Home-Assistant-shaped verbs
 * where the verb doubled as the capability. This class is the "reinterpret" half
 * of the ADR's "retire or reinterpret" instruction, and it is deliberately the
 * half CSDM-02 performs.
 *
 * Retiring the enum outright means changing `scene_actions.action_type` — the
 * wire field every client sends and every telemetry dashboard groups by. That
 * migration belongs with CSDM-06 (frontend) and CSDM-07 (migration), which own
 * the other ends of it. Until then the wire keeps speaking `action_type` and the
 * domain reads it canonically, which is exactly what lets validation become
 * provider-neutral without a breaking API change.
 *
 * Note what the mapping reveals: three of the four legacy verbs are the SAME
 * canonical capability (`power`) under three operations. The old enum could not
 * express that, which is why `set_brightness` had nowhere to put its range.
 */
final class ActionTypeTranslation
{
    /**
     * The canonical pair a legacy action type denotes.
     *
     * @return array{0: CapabilityId, 1: Operation}
     */
    public static function toCanonical(ActionType $actionType): array
    {
        return match ($actionType) {
            ActionType::TurnOn => [CapabilityId::Power, Operation::On],
            ActionType::TurnOff => [CapabilityId::Power, Operation::Off],
            ActionType::Toggle => [CapabilityId::Power, Operation::Toggle],
            ActionType::SetBrightness => [CapabilityId::Brightness, Operation::Set],
        };
    }

    /**
     * Same, from the raw wire string. Returns null for a value outside the
     * legacy enum — the caller decides whether that is a validation error or an
     * unsupported action, since the two have different outcomes.
     *
     * @return array{0: CapabilityId, 1: Operation}|null
     */
    public static function tryFromWire(string $actionType): ?array
    {
        $type = ActionType::tryFrom($actionType);

        return $type === null ? null : self::toCanonical($type);
    }
}
