<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * Provider slugs for Smart Home integrations.
 *
 * MVP ships: home_assistant only.
 * Future provider slugs are reserved here to document the expected values
 * and to avoid hardcoding strings across the codebase.
 *
 * When a new provider ships, add its case and update mvpAllowed() if applicable.
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

    /** Returns the MVP-allowed provider slugs (home_assistant only). */
    public static function mvpAllowed(): array
    {
        return [self::HomeAssistant];
    }

    public function isMvpSupported(): bool
    {
        return $this === self::HomeAssistant;
    }
}
