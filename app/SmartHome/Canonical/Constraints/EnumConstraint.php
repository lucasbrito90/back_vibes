<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical\Constraints;

use InvalidArgumentException;

final readonly class EnumConstraint implements Constraint
{
    /**
     * @param  list<string>  $allowedValues
     */
    public function __construct(
        public array $allowedValues,
    ) {
        if ($this->allowedValues === []) {
            throw new InvalidArgumentException('enum constraint requires a non-empty allowed_values list.');
        }
    }

    public function type(): string
    {
        return 'enum';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== 'enum') {
            throw new InvalidArgumentException('Constraint type must be "enum".');
        }

        $allowed = $data['allowed_values'] ?? null;
        if (! is_array($allowed) || $allowed === []) {
            throw new InvalidArgumentException('enum constraint requires a non-empty allowed_values list.');
        }

        foreach ($allowed as $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException('enum allowed_values must be non-empty strings.');
            }
        }

        /** @var list<string> $allowed */
        return new self(array_values($allowed));
    }

    /**
     * @return array{type: string, allowed_values: list<string>}
     */
    public function toArray(): array
    {
        return [
            'type' => 'enum',
            'allowed_values' => $this->allowedValues,
        ];
    }

    public function equals(Constraint $other): bool
    {
        return $other instanceof self && $other->allowedValues === $this->allowedValues;
    }
}
