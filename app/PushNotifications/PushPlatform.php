<?php

declare(strict_types=1);

namespace App\PushNotifications;

/**
 * Client platform values for push token registration.
 *
 * MVP ships: android only.
 * iOS and web are reserved for future phases.
 *
 * References: ADR-017, ADR-018, spec.md §4.
 */
enum PushPlatform: string
{
    case Android = 'android';
    case Ios = 'ios';
    case Web = 'web';

    /** Returns all valid platform value strings (for use in validation rules). */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Returns MVP-allowed platform cases (android only). */
    public static function mvpAllowed(): array
    {
        return [self::Android];
    }

    public function isMvpSupported(): bool
    {
        return $this === self::Android;
    }
}
