<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical\Constraints;

use InvalidArgumentException;

final readonly class NumberConstraint implements Constraint
{
    public function __construct(
        public ?float $min,
        public ?float $max,
        public ?float $step,
        public string $unit,
    ) {
        if ($this->unit === '') {
            throw new InvalidArgumentException('number constraint requires a non-empty unit.');
        }
    }

    public function type(): string
    {
        return 'number';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== 'number') {
            throw new InvalidArgumentException('Constraint type must be "number".');
        }

        if (! array_key_exists('unit', $data) || ! is_string($data['unit']) || $data['unit'] === '') {
            throw new InvalidArgumentException('number constraint requires a non-empty unit.');
        }

        foreach (['min', 'max', 'step'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("number constraint requires key \"{$key}\".");
            }
            $value = $data[$key];
            if ($value !== null && ! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException("number constraint \"{$key}\" must be numeric or null.");
            }
        }

        return new self(
            min: self::nullableFloat($data['min']),
            max: self::nullableFloat($data['max']),
            step: self::nullableFloat($data['step']),
            unit: $data['unit'],
        );
    }

    /**
     * @return array{type: string, min: float|null, max: float|null, step: float|null, unit: string}
     */
    public function toArray(): array
    {
        return [
            'type' => 'number',
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'unit' => $this->unit,
        ];
    }

    public function equals(Constraint $other): bool
    {
        return $other instanceof self
            && $other->min === $this->min
            && $other->max === $this->max
            && $other->step === $this->step
            && $other->unit === $this->unit;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return (float) $value;
    }
}
