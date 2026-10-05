<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * Typed aliases for known provider slugs (ADR-045 Decision 1).
 *
 * This enum is a type-safe alias layer only — it is NOT the identity source.
 * The canonical source of provider identity is config('smart_home.known_providers')
 * read via ProviderDescriptorRegistry. Cases here must stay aligned with that
 * config; adding a case does NOT register a provider.
 *
 * Reserved cases document future slugs to prevent string drift; a follow-up
 * ADR is required before any reserved provider ships.
 */
enum ProviderType: string
{
    case HomeAssistant = 'home_assistant';

    /** Reserved — future provider. No adapter or migration until a follow-up ADR. */
    case Tuya = 'tuya';

    /** Reserved — future provider. */
    case PhilipsHue = 'philips_hue';

    /** Reserved — future provider. */
    case Alexa = 'alexa';

    /** Reserved — future provider. */
    case GoogleHome = 'google_home';

    /** Reserved — future provider. */
    case Matter = 'matter';
}
