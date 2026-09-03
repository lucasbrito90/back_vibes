<?php

declare(strict_types=1);

namespace App\Telemetry\SmartHome;

/**
 * Bounded classification of which action type a SceneActionJob execution
 * targets, for the `action_type` label on `ixora.smart_home.action.total`
 * and `ixora.smart_home.action.duration` (TD-2, backend-business-telemetry-
 * validation.md §13).
 *
 * Deliberately a Telemetry-layer enum, not a re-export of
 * App\SmartHome\ActionType — this module never imports that domain enum
 * (Dependency Rule: Telemetry depends only on Telemetry Contracts), exactly
 * mirroring SmartHomeActionProvider::fromProviderSlug()'s existing
 * normalization precedent for the `provider` label.
 *
 * The caller (SceneActionJob) passes the raw action_type string it
 * already has ($action->action_type) to fromActionTypeSlug(), which
 * normalizes any value this Telemetry layer does not explicitly know about
 * to the reserved Other case. This matters beyond MVP-domain-enum drift: an
 * "unsupported action type" attempt (App\SmartHome\Exceptions\
 * UnsupportedSmartHomeActionException, outcome=unsupported) can carry an
 * arbitrary caller-supplied string that was never validated against
 * App\SmartHome\ActionType at all — without this normalization, that path
 * would give an unbounded label value and violate metrics-philosophy.md §6's
 * cardinality rule.
 */
enum SmartHomeActionType: string
{
    case TurnOn = 'turn_on';
    case TurnOff = 'turn_off';
    case Toggle = 'toggle';
    case Other = 'other';

    public static function fromActionTypeSlug(string $slug): self
    {
        return self::tryFrom($slug) ?? self::Other;
    }
}
