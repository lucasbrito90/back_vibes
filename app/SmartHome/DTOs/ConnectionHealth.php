<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

/**
 * Health of a provider connection, as returned by
 * ProviderAdapter::testConnection(). Immutable.
 *
 * `reachable` is true when the provider answered with a successful (2xx)
 * response. On transport failure `reachable` is false and `status_code` is null.
 */
final readonly class ConnectionHealth
{
    public function __construct(
        public bool $reachable,
        public ?int $status_code,
        public ?int $latency_ms,
        public ?string $error_message,
    ) {}
}
