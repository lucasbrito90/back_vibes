<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

/**
 * Declares the expected shape of a single config or credential field for a
 * provider connection — types and constraints only, never example values.
 */
final readonly class ProviderFieldSchema
{
    public function __construct(
        public string $type,
        public bool $required = true,
        public ?string $format = null,
    ) {}

    /**
     * @param  array{type: string, required?: bool, format?: string|null}  $config
     */
    public static function fromConfigArray(array $config): self
    {
        return new self(
            type: $config['type'],
            required: $config['required'] ?? true,
            format: $config['format'] ?? null,
        );
    }

    /**
     * @return array{type: string, required: bool, format?: string}
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'required' => $this->required,
        ];

        if ($this->format !== null) {
            $data['format'] = $this->format;
        }

        return $data;
    }
}
