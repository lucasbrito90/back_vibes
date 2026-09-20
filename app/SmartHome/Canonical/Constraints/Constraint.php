<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical\Constraints;

interface Constraint
{
    public function type(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self;

    public function equals(self $other): bool;
}
