<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical\Constraints;

use InvalidArgumentException;

final readonly class BooleanConstraint implements Constraint
{
    public function type(): string
    {
        return 'boolean';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== 'boolean') {
            throw new InvalidArgumentException('Constraint type must be "boolean".');
        }

        return new self;
    }

    /**
     * @return array{type: string}
     */
    public function toArray(): array
    {
        return ['type' => 'boolean'];
    }

    public function equals(Constraint $other): bool
    {
        return $other instanceof self;
    }
}
