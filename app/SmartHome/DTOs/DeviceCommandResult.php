<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

/**
 * The outcome of a DEV-02 direct device command attempt.
 *
 * - executed: the backend sent the command to the provider and it succeeded.
 * - client_execute: the provider does not declare ServerSideExecution; the
 *   mobile client must execute the command itself and may report the outcome
 *   via POST /api/scene-action-executions/report if a scene context is
 *   present. The backend performed no provider call.
 *
 * Failures (provider unreachable, provider refused the command) are not
 * represented here — they are thrown as exceptions and mapped to 502 by the
 * controller. This keeps the DTO's status set to non-error outcomes only.
 */
final readonly class DeviceCommandResult
{
    public function __construct(
        public string $status,
    ) {}

    public static function executed(): self
    {
        return new self('executed');
    }

    public static function clientExecute(): self
    {
        return new self('client_execute');
    }
}
