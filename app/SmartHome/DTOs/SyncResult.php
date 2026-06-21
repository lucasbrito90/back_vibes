<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

/**
 * Summary of a completed device sync operation.
 *
 * Returned by ProviderDeviceSyncService and serialised as the sync endpoint
 * response body. Immutable.
 */
final readonly class SyncResult
{
    public function __construct(
        public int $provider_connection_id,
        public int $synced,
        public int $created,
        public int $updated,
        public int $offline,
        public string $status,
    ) {}
}
