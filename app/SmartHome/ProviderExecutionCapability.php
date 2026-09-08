<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * Closed vocabulary of provider-level execution capabilities (ADR-036
 * Decision 2), following the same closed-vocabulary/fail-safe-default
 * precedent as ADR-033's device capabilities.
 *
 * These describe what a PROVIDER can do at the execution layer — they are
 * orthogonal to, and must never be merged or cross-validated with, a
 * DEVICE's own capabilities (ADR-033's `can_*` vocabulary). A device may be
 * dimmable while its provider cannot be scheduled; these are independent
 * facts. Provider execution capabilities are static per provider slug,
 * declared in config('smart_home.provider_descriptors.<slug>.execution_capabilities'),
 * never per-connection or per-device.
 *
 * - DeviceDiscovery: the provider can enumerate the user's devices.
 * - StateRead: current device state can be read.
 * - InteractiveExecution: commands can be executed while the user is
 *   present in the app.
 * - ServerSideExecution: the backend can execute commands using stored
 *   credentials, with no client involved.
 * - ScheduledExecution: commands can be executed by a backend-initiated
 *   schedule with the app closed. Implies ServerSideExecution.
 * - AutomationDelegation: recurring intent can be handed to the provider's
 *   own automation engine. Reserved — unused in v1.6.0.
 */
enum ProviderExecutionCapability: string
{
    case DeviceDiscovery = 'device_discovery';
    case StateRead = 'state_read';
    case InteractiveExecution = 'interactive_execution';
    case ServerSideExecution = 'server_side_execution';
    case ScheduledExecution = 'scheduled_execution';
    case AutomationDelegation = 'automation_delegation';

    /** Returns all valid execution capability values as strings (for validation/config checks). */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
