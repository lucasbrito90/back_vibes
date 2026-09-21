<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

use InvalidArgumentException;

/**
 * Versioned capability map for persistence/transmission (ADR-037 §10).
 */
final readonly class CanonicalCapabilitiesDocument
{
    /**
     * @param  array<string, Capability>  $capabilities  keyed by capability id
     */
    public function __construct(
        public string $contractVersion,
        public array $capabilities,
    ) {
        if ($this->contractVersion === '') {
            throw new InvalidArgumentException('contract_version is required.');
        }

        foreach ($this->capabilities as $key => $capability) {
            if ($key !== $capability->id->value) {
                throw new InvalidArgumentException('Capability map keys must match capability.id.');
            }
        }
    }

    public static function emptyV1(): self
    {
        return new self(ContractVersion::CURRENT, []);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $version = $data['contract_version'] ?? null;
        if (! is_string($version) || $version === '') {
            throw new InvalidArgumentException('contract_version is required.');
        }

        $capabilitiesRaw = $data['capabilities'] ?? null;
        if (! is_array($capabilitiesRaw)) {
            throw new InvalidArgumentException('capabilities must be an object.');
        }

        $capabilities = [];
        foreach ($capabilitiesRaw as $key => $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Each capability entry must be an object.');
            }
            if (! isset($entry['id']) && is_string($key)) {
                $entry['id'] = $key;
            }
            $capability = Capability::fromArray($entry);
            $capabilities[$capability->id->value] = $capability;
        }

        return new self($version, $capabilities);
    }

    /**
     * @return array{contract_version: string, capabilities: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        $capabilities = [];
        foreach ($this->capabilities as $id => $capability) {
            $capabilities[$id] = $capability->toArray();
        }

        return [
            'contract_version' => $this->contractVersion,
            'capabilities' => $capabilities,
        ];
    }
}
