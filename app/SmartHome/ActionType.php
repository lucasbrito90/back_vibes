<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * MVP device action types for scene_actions.
 *
 * MVP supports: turn_on, turn_off, toggle, set_brightness (ADR-033).
 * Future types (set_color, activate_scene, …) are NOT added here until a
 * follow-up spec is accepted — parameters JSON field is already generic.
 *
 * References: ADR-015 §MVP action types, ADR-033 decision 3.
 */
enum ActionType: string
{
    case TurnOn = 'turn_on';
    case TurnOff = 'turn_off';
    case Toggle = 'toggle';
    case SetBrightness = 'set_brightness';

    /** Returns all MVP-allowed action type cases. */
    public static function mvpAllowed(): array
    {
        return [self::TurnOn, self::TurnOff, self::Toggle, self::SetBrightness];
    }

    /** All current cases are MVP-supported; reserved here for symmetry with ProviderType. */
    public function isMvpSupported(): bool
    {
        return true;
    }

    /** Ixora capability key required before dispatch (ADR-033 decision 1). */
    public function requiredCapability(): string
    {
        return match ($this) {
            self::TurnOn => 'can_turn_on',
            self::TurnOff => 'can_turn_off',
            self::Toggle => 'can_toggle',
            self::SetBrightness => 'can_set_brightness',
        };
    }

    /**
     * ADR-033 fail-open: null capabilities never block. A non-null map missing the
     * required key means the action must not be dispatched or stored.
     */
    public static function isBlockedByDeviceCapabilities(?array $capabilities, string $actionType): bool
    {
        $type = self::tryFrom($actionType);

        if ($type === null || $capabilities === null) {
            return false;
        }

        return ! array_key_exists($type->requiredCapability(), $capabilities);
    }
}
