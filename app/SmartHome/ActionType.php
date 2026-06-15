<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * MVP device action types for vibe_device_actions.
 *
 * MVP supports: turn_on, turn_off, toggle.
 * Future types (set_brightness, set_color, activate_scene, …) are NOT added here
 * until a follow-up spec is accepted — parameters JSON field is already generic.
 *
 * References: ADR-015 §MVP action types, spec.md §2.7 Actions MVP.
 */
enum ActionType: string
{
    case TurnOn = 'turn_on';
    case TurnOff = 'turn_off';
    case Toggle = 'toggle';

    /** Returns all MVP-allowed action type cases. */
    public static function mvpAllowed(): array
    {
        return [self::TurnOn, self::TurnOff, self::Toggle];
    }

    /** All current cases are MVP-supported; reserved here for symmetry with ProviderType. */
    public function isMvpSupported(): bool
    {
        return true;
    }
}
