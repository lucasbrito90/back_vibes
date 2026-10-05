<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * Dual-read path for ADR-033 legacy capability maps (ADR-037 §8). Fail-open: null in → null out;
 * unknown legacy keys are skipped without throwing.
 */
final class LegacyCapabilitiesReader
{
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
     * Delegates to the reader that owns the legacy shape, rather than
     * reconstructing it a second time.
     *
     * This method previously called `CapabilityCatalog::definition()`, which
     * does not exist — it would have thrown an Error on every legacy row. It
     * never did, because nothing called this class until DEV-01 needed to read
     * stored state back; the defect sat in a dead branch. Delegating also fixes
     * a semantic bug the broken version carried: it granted `power` the full
     * catalog operation set whatever the row declared, where
     * LegacyCapabilityMapReader derives only the operations whose `can_*` keys
     * are actually present.
     *
     * @param  array<string, mixed>  $legacy
     */
    private function fromLegacyMap(array $legacy): CanonicalCapabilitiesDocument
    {
        $capabilities = [];

        foreach (LegacyCapabilityMapReader::read($legacy) as $capability) {
            $capabilities[$capability->id->value] = $capability;
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
