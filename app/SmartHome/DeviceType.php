<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * Normalised IXORA device type vocabulary for the device registry.
 *
 * Values are provider-agnostic category labels stored in devices.type.
 * Adapters map provider-specific identifiers (e.g. Home Assistant entity
 * domains) to these cases at sync time; the original provider domain is
 * preserved separately in devices.metadata['domain'].
 *
 * MVP ships the five cases below. Future provider adapters map their own
 * taxonomies here without echoing provider-native slugs in this column.
 */
enum DeviceType: string
{
    case Lighting = 'lighting';
    case Switchable = 'switchable';
    case Media = 'media';
    case Ventilation = 'ventilation';
    case Other = 'other';

    /** Returns all valid type value strings (for use in validation rules). */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
