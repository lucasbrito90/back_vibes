<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

use App\SmartHome\Canonical\Exceptions\InvalidCapabilityDefinitionException;

/**
 * Typed constraint on a capability's value — a closed tagged union (ADR-037 §4).
 *
 * Replaces ADR-033's single untyped `{min, max, step}` shape, which had no unit,
 * no precedent for enums, and exactly one implementation ever populating it.
 * Extended in future by adding a case (e.g. a structured colour constraint),
 * never by loosening an existing one.
 */
abstract readonly class Constraint
{
    /** @return array<string, mixed> */
    abstract public function toArray(): array;

    abstract public function type(): string;

    /**
     * Rebuilds a constraint from its canonical array form.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidCapabilityDefinitionException
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;

        return match ($type) {
            'number' => NumberConstraint::fromArray($data),
            'enum' => EnumConstraint::fromArray($data),
            'boolean' => new BooleanConstraint,
            default => throw new InvalidCapabilityDefinitionException(
                sprintf(
                    'Unknown constraint type [%s]. The canonical union is closed: number, enum, boolean.',
                    is_scalar($type) ? (string) $type : gettype($type),
                ),
            ),
        };
    }
}
