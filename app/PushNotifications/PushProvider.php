<?php

declare(strict_types=1);

namespace App\PushNotifications;

/**
 * Push transport provider slugs for device token registration.
 *
 * MVP ships: fcm only.
 * Future providers (e.g. direct APNs adapter slug) are reserved for documentation.
 *
 * References: ADR-017, spec.md §4.
 */
enum PushProvider: string
{
    case Fcm = 'fcm';

    /** Returns all valid provider value strings (for use in validation rules). */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Returns MVP-allowed push provider cases (FCM only). */
    public static function mvpAllowed(): array
    {
        return [self::Fcm];
    }

    public function isMvpSupported(): bool
    {
        return $this === self::Fcm;
    }
}
