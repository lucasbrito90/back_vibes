<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical\Constraints;

use InvalidArgumentException;

final class ConstraintFactory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): Constraint
    {
        $type = $data['type'] ?? null;

        return match ($type) {
            'number' => NumberConstraint::fromArray($data),
            'enum' => EnumConstraint::fromArray($data),
            'boolean' => BooleanConstraint::fromArray($data),
            default => throw new InvalidArgumentException('Unknown or missing constraint type.'),
        };
    }
}
