<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

use App\SmartHome\Canonical\Exceptions\InvalidCapabilityDefinitionException;

/**
 * A numeric capability value with a mandatory unit (ADR-037 §4).
 *
 * `min`, `max` and `step` are each independently nullable, and null means NOT
 * KNOWN — never "unbounded", never zero. A provider mapper frequently knows a
 * unit without knowing bounds (a thermostat reporting current_temperature in
 * Celsius with no documented sensor range); forcing it to invent bounds would
 * smuggle a fabricated guess into a field the contract requires to be
 * domain-true. The one deliberate exception in reading, not writing: `max: null`
 * on `energy` also reads as unbounded — disambiguated per capability by the
 * catalog, not by this shape.
 *
 * `unit` is mandatory unconditionally: a numeric value with a genuinely unknown
 * unit is not representable by this model, and that is evidence the capability
 * needs a different shape rather than evidence the field should be optional.
 *
 * Disclosed trade-off (ADR-037 §4): a null bound on a writable capability means
 * the CSDM-02 validation pipeline cannot enforce that dimension — it degrades to
 * type-checking, visibly, not silently.
 */
final readonly class NumberConstraint extends Constraint
{
    public function __construct(
        public ?float $min,
        public ?float $max,
        public ?float $step,
        public Unit $unit,
    ) {
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidCapabilityDefinitionException(
                sprintf('A number constraint cannot declare min (%s) greater than max (%s).', $min, $max),
            );
        }

        if ($step !== null && $step <= 0) {
            throw new InvalidCapabilityDefinitionException(
                'A number constraint step must be greater than zero; use null to declare the resolution unknown.',
            );
        }
    }

    public function type(): string
    {
        return 'number';
    }

    /** @return array{type: string, min: float|null, max: float|null, step: float|null, unit: string} */
    public function toArray(): array
    {
        return [
            'type' => 'number',
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'unit' => $this->unit->value,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('unit', $data) || ! is_string($data['unit'])) {
            throw new InvalidCapabilityDefinitionException(
                'A number constraint requires a unit; it is mandatory unconditionally (ADR-037 §4).',
            );
        }

        $unit = Unit::tryFrom($data['unit']);

        if ($unit === null) {
            throw new InvalidCapabilityDefinitionException(
                sprintf('Unknown canonical unit [%s].', $data['unit']),
            );
        }

        return new self(
            self::nullableNumber($data, 'min'),
            self::nullableNumber($data, 'max'),
            self::nullableNumber($data, 'step'),
            $unit,
        );
    }

    /** @param array<string, mixed> $data */
    private static function nullableNumber(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidCapabilityDefinitionException(
                sprintf('A number constraint %s must be numeric or null.', $key),
            );
        }

        return (float) $value;
    }
}
