<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

use InvalidArgumentException;
use RuntimeException;

/**
 * Loads the vendorized JSON Schema artifact and exposes contract metadata.
 */
final class CapabilitySchema
{
    /**
     * @return array<string, mixed>
     */
    public static function loadDecoded(): array
    {
        $path = self::absolutePath();
        if (! is_readable($path)) {
            throw new RuntimeException("Capability schema not found at {$path}.");
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Failed to read capability schema.');
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Capability schema is not valid JSON.');
        }

        return $decoded;
    }

    public static function assertContractVersionMatchesCode(): void
    {
        $schema = self::loadDecoded();
        $schemaVersion = $schema['contract_version'] ?? null;
        if ($schemaVersion !== ContractVersion::CURRENT) {
            throw new InvalidArgumentException(
                'Vendorized schema contract_version does not match ContractVersion::CURRENT.',
            );
        }

        $schemaId = $schema['$id'] ?? null;
        if ($schemaId !== ContractVersion::SCHEMA_ID) {
            throw new InvalidArgumentException('Vendorized schema $id does not match ContractVersion::SCHEMA_ID.');
        }
    }

    public static function absolutePath(): string
    {
        return base_path(ContractVersion::SCHEMA_RELATIVE_PATH);
    }
}
