<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * Dual-read path for ADR-033 legacy capability maps (ADR-037 §8). Fail-open: null in → null out;
 * unknown legacy keys are skipped without throwing.
 */
final class LegacyCapabilitiesReader
{
    private const LEGACY_POWER_KEYS = [
        'can_turn_on' => OperationId::On,
        'can_turn_off' => OperationId::Off,
        'can_toggle' => OperationId::Toggle,
    ];

    /**
     * @param  array<string, mixed>|null  $stored  Raw JSON from devices.capabilities or an envelope
     */
    public function read(?array $stored): ?CanonicalCapabilitiesDocument
    {
        if ($stored === null) {
            return null;
        }

        if (isset($stored['contract_version'], $stored['capabilities']) && is_array($stored['capabilities'])) {
            return CanonicalCapabilitiesDocument::fromArray($stored);
        }

        if ($this->isLegacyAdr033Map($stored)) {
            return $this->fromLegacyMap($stored);
        }

        return $this->fromCanonicalCapabilityMap($stored);
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private function isLegacyAdr033Map(array $stored): bool
    {
        foreach (array_keys($stored) as $key) {
            if (is_string($key) && str_starts_with($key, 'can_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $legacy
     */
    private function fromLegacyMap(array $legacy): CanonicalCapabilitiesDocument
    {
        $capabilities = [];

        $hasPowerLegacy = false;
        foreach (array_keys(self::LEGACY_POWER_KEYS) as $legacyKey) {
            if (array_key_exists($legacyKey, $legacy)) {
                $hasPowerLegacy = true;
                break;
            }
        }

        if ($hasPowerLegacy) {
            $capabilities[CapabilityId::Power->value] = CapabilityCatalog::definition(CapabilityId::Power);
        }

        if (array_key_exists('can_set_brightness', $legacy)) {
            $capabilities[CapabilityId::Brightness->value] = CapabilityCatalog::definition(CapabilityId::Brightness);
        }

        return new CanonicalCapabilitiesDocument(ContractVersion::CURRENT, $capabilities);
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function fromCanonicalCapabilityMap(array $map): CanonicalCapabilitiesDocument
    {
        $capabilities = [];

        foreach ($map as $key => $entry) {
            if (! is_string($key) || ! is_array($entry)) {
                continue;
            }

            if (! isset($entry['access'], $entry['operations'])) {
                continue;
            }

            try {
                $entry['id'] = $entry['id'] ?? $key;
                $capability = Capability::fromArray($entry);
                $capabilities[$capability->id->value] = $capability;
            } catch (\InvalidArgumentException) {
                // Fail-open during transition: skip entries that are not yet canonical.
                continue;
            }
        }

        return new CanonicalCapabilitiesDocument(ContractVersion::CURRENT, $capabilities);
    }
}
