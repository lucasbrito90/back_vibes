<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Models\Device;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\Operation;
use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\DTOs\DeviceCommandResult;
use App\SmartHome\Exceptions\ProviderCommandFailedException;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;
use App\SmartHome\ProviderAdapterResolver;
use App\SmartHome\ProviderDescriptorRegistry;
use App\SmartHome\ProviderExecutionCapability;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use App\Telemetry\SmartHome\SmartHomeActionProvider;
use App\Telemetry\SmartHome\SmartHomeActionTelemetry;
use App\Telemetry\SmartHome\SmartHomeActionType;
use Throwable;

/**
 * Executes one canonical capability operation directly against a device
 * (DEV-02, ADR-037 §13).
 *
 * Validation (capability exists, operation admissible, parameters within
 * constraint) happens in ExecuteDeviceCommandRequest — by the time this
 * service is called the command is structurally and semantically sound.
 *
 * Server-side vs device-side split is resolved via ProviderDescriptorRegistry
 * execution_capabilities — never a provider slug comparison.
 *
 * Telemetry is recorded via SmartHomeActionTelemetry for every server-side
 * attempt, using the same abstraction and vocabulary as SceneActionJob.
 *
 * Throws ProviderCommandFailedException when the provider is reachable but
 * the command did not complete (transport failure or non-2xx response).
 * Throws UnsupportedSmartHomeActionException when the adapter cannot map the
 * canonical command to a provider service call.
 */
final class DeviceCommandService
{
    /**
     * Reverse translation from canonical (CapabilityId, Operation) to the
     * legacy action-type string the existing ProviderAdapter interface expects.
     *
     * The legacy action type doubles as the identifier the adapter uses to look
     * up its service call map. This mapping is the only point where the two
     * vocabularies meet; it is intentionally constrained to the four MVP actions
     * whose adapters are implemented. Operations outside this set that somehow
     * pass validation (e.g. target_temperature on an HA device without a climate
     * entity) reach the adapter as an unmappable string and throw
     * UnsupportedSmartHomeActionException there — the same outcome as before
     * DEV-02 existed.
     *
     * @var array<string, string> keyed by "{capability_id}/{operation}"
     */
    private const CANONICAL_TO_LEGACY = [
        'power/on' => 'turn_on',
        'power/off' => 'turn_off',
        'power/toggle' => 'toggle',
        'brightness/set' => 'set_brightness',
    ];

    public function __construct(
        private readonly ProviderAdapterResolver $adapterResolver,
        private readonly ProviderDescriptorRegistry $descriptorRegistry,
        private readonly SmartHomeActionTelemetry $actionTelemetry,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters  canonical parameters (key "value")
     *
     * @throws UnsupportedSmartHomeActionException when the adapter cannot map the command
     * @throws ProviderCommandFailedException when the provider returned a failure
     */
    public function execute(
        Device $device,
        CapabilityId $capabilityId,
        Operation $operation,
        array $parameters = [],
    ): DeviceCommandResult {
        if (! $this->supportsServerSideExecution($device)) {
            return DeviceCommandResult::clientExecute();
        }

        $connection = $device->providerConnection;

        $legacyAction = self::CANONICAL_TO_LEGACY["{$capabilityId->value}/{$operation->value}"]
            ?? "{$capabilityId->value}/{$operation->value}";

        $adapter = $this->adapterResolver->forProvider($connection->provider);

        $wrap = $this->actionTelemetry->wrapWithMetadata(
            SmartHomeActionProvider::fromProviderSlug($connection->provider),
            SmartHomeActionType::fromActionTypeSlug($legacyAction),
            fn (): ActionResult => $adapter->executeAction(
                $connection,
                $device->provider_device_id,
                $legacyAction,
                $parameters,
            ),
            fn (ActionResult $result): SmartHomeActionOutcome => $result->success
                ? SmartHomeActionOutcome::Success
                : SmartHomeActionOutcome::Failure,
            fn (Throwable $e): SmartHomeActionOutcome => $e instanceof UnsupportedSmartHomeActionException
                ? SmartHomeActionOutcome::Unsupported
                : SmartHomeActionOutcome::Failure,
        );

        if ($wrap->thrownException instanceof UnsupportedSmartHomeActionException) {
            throw $wrap->thrownException;
        }

        if ($wrap->thrownException !== null) {
            throw new ProviderCommandFailedException(
                'Provider execution failed.',
                0,
                $wrap->thrownException,
            );
        }

        /** @var ActionResult $result */
        $result = $wrap->result;

        if (! $result->success) {
            throw new ProviderCommandFailedException(
                $result->error_message ?? 'Provider returned a failure response.',
            );
        }

        return DeviceCommandResult::executed();
    }

    private function supportsServerSideExecution(Device $device): bool
    {
        $descriptor = $this->descriptorRegistry->forSlug($device->provider);

        return in_array(
            ProviderExecutionCapability::ServerSideExecution,
            $descriptor->executionCapabilities,
            true,
        );
    }
}
