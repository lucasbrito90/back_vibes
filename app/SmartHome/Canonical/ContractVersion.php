<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * Version marker for persisted/transmitted canonical capability data (ADR-037 §10).
 */
final class ContractVersion
{
    public const CURRENT = 'csdm/v1';

    public const SCHEMA_RELATIVE_PATH = 'contracts/smart-home/capability.v1.schema.json';

    public const SCHEMA_ID = 'https://ixora-app.app/contracts/smart-home/capability.v1.schema.json';

    private function __construct() {}
}
