<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

use App\SmartHome\ConnectionStatus;

/**
 * Result of a single connectivity test attempt (server-side or client-reported).
 */
final readonly class ConnectionTestResult
{
    public function __construct(
        public ConnectionStatus $newStatus,
        /** 'success' | 'failure_host' | 'failure_credentials' */
        public string $outcome,
        public ?int $latencyMs,
        /** Brief text; MUST NOT contain credential values. */
        public ?string $failureReason,
        public int $attemptId,
    ) {}
}
