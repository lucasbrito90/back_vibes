<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

use App\SmartHome\Canonical\Exceptions\InvalidCapabilityDefinitionException;

/**
 * A capability whose value is one of a closed set of strings (ADR-037 §4).
 *
 * The set is per-device and supplied by the provider mapper — a thermostat's
 * hvac_mode may legitimately offer fewer modes than another's. What the
 * contract fixes is that the set exists, is non-empty, and is made of strings.
 */
final readonly class EnumConstraint extends Constraint
{
    /** @param list<string> $allowedValues */
    public function __construct(public array $allowedValues)
    {
        if ($allowedValues === []) {
            throw new InvalidCapabilityDefinitionException(
                'An enum constraint must declare at least one allowed value; an empty set describes a capability that can never hold a valid value.',
            );
        }

        foreach ($allowedValues as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidCapabilityDefinitionException(
                    'Enum allowed_values must be non-empty strings.',
                );
            }
        }

        if (count(array_unique($allowedValues)) !== count($allowedValues)) {
            throw new InvalidCapabilityDefinitionException(
                'Enum allowed_values must be unique.',
            );
        }
    }

    public function type(): string
    {
        return 'enum';
    }

    /** @return array{type: string, allowed_values: list<string>} */
    public function toArray(): array
    {
        return ['type' => 'enum', 'allowed_values' => array_values($this->allowedValues)];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $values = $data['allowed_values'] ?? null;

        if (! is_array($values)) {
            throw new InvalidCapabilityDefinitionException(
                'An enum constraint requires an allowed_values array.',
            );
        }

        /** @var list<string> $values */
        $values = array_values($values);

        return new self($values);
    }
}
