<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * A capability whose value is true/false (ADR-037 §4, PO correction 2).
 *
 * `power` carries this rather than a null constraint: its readable state needs
 * a typed shape exactly as `brightness`'s does, even though its operations
 * (on/off/toggle) take no parameters. Operations and constraints are
 * independent — a parameterless operation does not imply an untyped value.
 */
final readonly class BooleanConstraint extends Constraint
{
    public function type(): string
    {
        return 'boolean';
    }

    /** @return array{type: string} */
    public function toArray(): array
    {
        return ['type' => 'boolean'];
    }
}
