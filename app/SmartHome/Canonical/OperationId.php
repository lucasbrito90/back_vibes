<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

enum OperationId: string
{
    case On = 'on';
    case Off = 'off';
    case Toggle = 'toggle';
    case Set = 'set';

    public static function tryFromString(string $operation): ?self
    {
        return self::tryFrom($operation);
    }
}
