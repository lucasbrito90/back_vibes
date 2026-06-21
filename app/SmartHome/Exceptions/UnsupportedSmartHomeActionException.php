<?php

declare(strict_types=1);

namespace App\SmartHome\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an action type is not supported by the adapter / not mappable to
 * a provider service call.
 *
 * MVP supported actions: turn_on, turn_off, toggle.
 */
final class UnsupportedSmartHomeActionException extends InvalidArgumentException
{
    public static function forAction(string $action): self
    {
        return new self("Unsupported smart home action [{$action}].");
    }
}
