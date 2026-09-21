<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * Canonical operation verbs (ADR-037 §3).
 *
 * Decoupled from any provider's service or command names. `Toggle` is a
 * first-class canonical operation on `power` regardless of whether a given
 * provider implements it as one native call (Home Assistant's light.toggle) or
 * as a composed read-invert-write (Google Home, per GH04) — that is the
 * mapper's problem, never the domain's.
 */
enum Operation: string
{
    case On = 'on';
    case Off = 'off';
    case Toggle = 'toggle';
    case Set = 'set';
}
