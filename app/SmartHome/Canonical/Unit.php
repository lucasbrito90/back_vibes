<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * Canonical units (ADR-037 §4-§5).
 *
 * Mandatory on every number constraint: a numeric value with a genuinely
 * unknown unit is not representable by this model. Provider-native scales
 * (Home Assistant's 0-255, Google Home's 0-254) are NOT units and never
 * appear here — they are protocol artifacts converted at the mapper boundary.
 */
enum Unit: string
{
    case Percent = 'percent';
    case KilowattHour = 'kWh';
    case Celsius = 'celsius';
}
