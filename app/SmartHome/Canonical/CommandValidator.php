<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * Validates a command against the device's canonical capabilities, before any
 * provider mapper is reached (ADR-037 §7).
 *
 * This is the fix for the confirmed bug the ADR names in its Context §5:
 * `SceneAction.parameters` is free-form JSON, and `HomeAssistantAdapter`
 * merges it straight into the outgoing service payload, so `brightness: 9999`
 * travels the whole domain and lands on the provider unexamined. Today the
 * adapter is effectively the only line of defence; after this class it is the
 * second one — a provider may still refuse a technically-in-range value for
 * reasons of its own, and should.
 *
 * Provider-neutral by construction: every decision here reads the canonical
 * capability contract, and no provider slug, service name, trait or native
 * scale appears anywhere in this file. That is enforceable, not just intended —
 * ProviderExtensibilityBoundaryTest fails on a hardcoded slug in any shape,
 * comparison included.
 *
 * The canonical parameter key is `value` (ADR-037 §12's worked command
 * example). Two deliberate limits on how far this class reaches:
 *
 * It polices what the contract governs and nothing else. A key the capability's
 * constraint says nothing about — a provider extra a caller passes through
 * today — is left alone rather than rejected. Ending that passthrough is real
 * work, owned by CSDM-03/CSDM-07 where the mapper takes over payload
 * construction; doing it here, as a side effect of installing a validator,
 * would break working behaviour for a reason unrelated to the defect.
 *
 * And a parameter still written in the pre-ADR-037 shape is range-checked
 * against the bounds the device's own legacy row declares, not refused. A
 * stored `{brightness: 200}` dims a real lamp today; `{brightness: 9999}` is
 * the bug. Both must stay true.
 */
final class CommandValidator
{
    /** The canonical parameter key carrying an operation's value. */
    public const VALUE_KEY = 'value';

    /**
     * Parameter keys a pre-ADR-037 client may still be sending, per capability.
     *
     * TRANSITIONAL. These rows work today — a stored `{brightness: 200}` reaches
     * Home Assistant and dims the lamp — so refusing them outright would break a
     * live path to install a validator, which is the opposite of the point. They
     * are instead range-checked against the bounds the DEVICE declared in its own
     * legacy capability row, so `9999` is rejected while `200` still passes. No
     * provider scale is named here or anywhere else in the domain; the numbers
     * come from the row the provider itself wrote.
     *
     * CSDM-03 removes this the moment the Home Assistant mapper emits canonical
     * rows and the legacy shape stops being produced.
     */
    private const LEGACY_PARAMETER_KEYS = [
        'brightness' => CapabilityId::Brightness,
    ];

    /**
     * @param  array<string, mixed>|null  $deviceCapabilities  raw `devices.capabilities`, canonical envelope or legacy ADR-033 map
     * @param  array<string, mixed>  $parameters
     */
    public function validate(
        ?array $deviceCapabilities,
        CapabilityId $capabilityId,
        Operation $operation,
        array $parameters = [],
    ): CommandValidationResult {
        $capability = $this->resolveCapability($deviceCapabilities, $capabilityId);

        // ADR-033 §5 fail-open, preserved verbatim by ADR-037 §8: a device whose
        // capabilities were never derived must keep behaving as it did before
        // the contract existed. Unknown is not the same as unsupported, and
        // treating it as such would silently disable working devices.
        if ($capability === null && $deviceCapabilities === null) {
            return CommandValidationResult::valid();
        }

        if ($capability === null) {
            return CommandValidationResult::reject(
                CommandRejectionReason::CapabilityUnavailable,
                sprintf('This device does not support %s.', $this->humanize($capabilityId)),
            );
        }

        if (! $capability->access->permitsWrite()) {
            return CommandValidationResult::reject(
                CommandRejectionReason::AccessForbidsOperation,
                sprintf('%s can only be read on this device, not changed.', $this->humanize($capabilityId)),
            );
        }

        if (! $capability->supports($operation)) {
            return CommandValidationResult::reject(
                CommandRejectionReason::OperationUnsupported,
                sprintf(
                    'This device does not support %s on %s.',
                    $operation->value,
                    $this->humanize($capabilityId),
                ),
            );
        }

        return $this->validateParameters($capability, $operation, $parameters, $deviceCapabilities);
    }

    /**
     * Validates a command expressed in the legacy wire vocabulary, translating
     * it to canonical first. The single entry point for call sites that still
     * speak `action_type` — which, until CSDM-06/CSDM-07 retire the field, is
     * all of them.
     *
     * @param  array<string, mixed>|null  $deviceCapabilities
     * @param  array<string, mixed>  $parameters
     */
    public function validateLegacyAction(
        ?array $deviceCapabilities,
        string $actionType,
        array $parameters = [],
    ): CommandValidationResult {
        $canonical = ActionTypeTranslation::tryFromWire($actionType);

        if ($canonical === null) {
            return CommandValidationResult::reject(
                CommandRejectionReason::OperationUnsupported,
                'The selected action is not a recognised action type.',
            );
        }

        [$capabilityId, $operation] = $canonical;

        return $this->validate($deviceCapabilities, $capabilityId, $operation, $parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>|null  $deviceCapabilities
     */
    private function validateParameters(
        Capability $capability,
        Operation $operation,
        array $parameters,
        ?array $deviceCapabilities,
    ): CommandValidationResult {
        $constraint = $capability->constraints;

        // Parameterless operations (power on/off/toggle) have no governed value,
        // so there is nothing here to range-check. Extra keys are left alone
        // rather than rejected: callers pass provider extras through today (a
        // fade time on turn_off, for instance), and that passthrough is a
        // separate concern from the defect this class fixes. Ending it belongs
        // with CSDM-03/CSDM-07, where the mapper takes ownership of payload
        // construction — not here, as a side effect of installing a validator.
        if ($operation !== Operation::Set) {
            return CommandValidationResult::valid();
        }

        $legacy = $this->legacyParameterFor($capability, $parameters, $deviceCapabilities);

        if ($legacy !== null) {
            return $legacy;
        }

        if (! array_key_exists(self::VALUE_KEY, $parameters)) {
            return CommandValidationResult::reject(
                CommandRejectionReason::ParameterMalformed,
                sprintf(
                    'Setting %s requires a "%s" parameter.',
                    $this->humanize($capability->id),
                    self::VALUE_KEY,
                ),
            );
        }

        $value = $parameters[self::VALUE_KEY];

        return match (true) {
            $constraint instanceof NumberConstraint => $this->validateNumber($capability, $constraint, $value),
            $constraint instanceof EnumConstraint => $this->validateEnum($capability, $constraint, $value),
            $constraint instanceof BooleanConstraint => $this->validateBoolean($capability, $value),
            default => CommandValidationResult::reject(
                CommandRejectionReason::ParameterMalformed,
                sprintf('%s does not accept a value.', $this->humanize($capability->id)),
            ),
        };
    }

    private function validateNumber(Capability $capability, NumberConstraint $constraint, mixed $value): CommandValidationResult
    {
        if (! is_int($value) && ! is_float($value)) {
            return CommandValidationResult::reject(
                CommandRejectionReason::ParameterMalformed,
                sprintf('%s must be a number.', $this->humanize($capability->id)),
            );
        }

        $numeric = (float) $value;
        $unit = $constraint->unit->value;

        // A null bound means the provider did not declare one (ADR-037 §4).
        // The check is skipped, never defaulted — validation degrades to type
        // checking for that dimension, visibly rather than silently.
        if ($constraint->min !== null && $numeric < $constraint->min) {
            return CommandValidationResult::reject(
                CommandRejectionReason::ParameterOutOfRange,
                sprintf('%s must be at least %s %s.', $this->humanize($capability->id), $this->format($constraint->min), $unit),
            );
        }

        if ($constraint->max !== null && $numeric > $constraint->max) {
            return CommandValidationResult::reject(
                CommandRejectionReason::ParameterOutOfRange,
                sprintf('%s must be at most %s %s.', $this->humanize($capability->id), $this->format($constraint->max), $unit),
            );
        }

        if ($constraint->step !== null && ! $this->isOnStep($numeric, $constraint)) {
            return CommandValidationResult::reject(
                CommandRejectionReason::ParameterOutOfRange,
                sprintf(
                    '%s must be set in increments of %s %s.',
                    $this->humanize($capability->id),
                    $this->format($constraint->step),
                    $unit,
                ),
            );
        }

        return CommandValidationResult::valid();
    }

    private function validateEnum(Capability $capability, EnumConstraint $constraint, mixed $value): CommandValidationResult
    {
        if (! is_string($value) || ! in_array($value, $constraint->allowedValues, true)) {
            return CommandValidationResult::reject(
                CommandRejectionReason::ParameterOutOfRange,
                sprintf(
                    '%s must be one of: %s.',
                    $this->humanize($capability->id),
                    implode(', ', $constraint->allowedValues),
                ),
            );
        }

        return CommandValidationResult::valid();
    }

    private function validateBoolean(Capability $capability, mixed $value): CommandValidationResult
    {
        if (! is_bool($value)) {
            return CommandValidationResult::reject(
                CommandRejectionReason::ParameterMalformed,
                sprintf('%s must be true or false.', $this->humanize($capability->id)),
            );
        }

        return CommandValidationResult::valid();
    }

    /**
     * Whether the value sits on the constraint's grid, measured from `min` when
     * one is declared and from zero otherwise. Uses a tolerance because the
     * grid may be fractional (a thermostat's 0.5 °C) and binary floats do not
     * land exactly on such multiples.
     */
    private function isOnStep(float $value, NumberConstraint $constraint): bool
    {
        $origin = $constraint->min ?? 0.0;
        $offset = $value - $origin;
        $steps = $offset / $constraint->step;
        $rounded = round($steps);

        return abs($steps - $rounded) < 1e-9;
    }

    /**
     * Resolves the device's capability, reading either shape during the
     * transition window (ADR-037 §8).
     *
     * @param  array<string, mixed>|null  $deviceCapabilities
     */
    private function resolveCapability(?array $deviceCapabilities, CapabilityId $capabilityId): ?Capability
    {
        if ($deviceCapabilities === null || $deviceCapabilities === []) {
            return null;
        }

        $capabilities = CapabilityContract::isCanonicalEnvelope($deviceCapabilities)
            ? $this->readCanonicalEnvelope($deviceCapabilities)
            : LegacyCapabilityMapReader::read($deviceCapabilities);

        foreach ($capabilities as $capability) {
            if ($capability->id === $capabilityId) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return list<Capability>
     */
    private function readCanonicalEnvelope(array $envelope): array
    {
        $capabilities = [];

        foreach ((array) ($envelope['capabilities'] ?? []) as $entry) {
            if (is_array($entry)) {
                $capabilities[] = Capability::fromArray($entry);
            }
        }

        return $capabilities;
    }

    /**
     * Range-checks a parameter still written in the pre-ADR-037 shape, against
     * the bounds the device's own legacy row declares. Returns null when the
     * payload is not legacy-shaped, so the canonical path takes over.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>|null  $deviceCapabilities
     */
    private function legacyParameterFor(
        Capability $capability,
        array $parameters,
        ?array $deviceCapabilities,
    ): ?CommandValidationResult {
        if ($deviceCapabilities === null || CapabilityContract::isCanonicalEnvelope($deviceCapabilities)) {
            return null;
        }

        $legacyKey = array_search($capability->id, self::LEGACY_PARAMETER_KEYS, true);

        if ($legacyKey === false || ! array_key_exists($legacyKey, $parameters)) {
            return null;
        }

        $bounds = LegacyCapabilityMapReader::declaredBrightnessBounds($deviceCapabilities);

        if ($bounds === null) {
            return null;
        }

        $value = $parameters[$legacyKey];

        if (! is_int($value) && ! is_float($value)) {
            return CommandValidationResult::reject(
                CommandRejectionReason::ParameterMalformed,
                sprintf('%s must be a number.', $this->humanize($capability->id)),
            );
        }

        $numeric = (float) $value;

        if ($bounds['min'] !== null && $numeric < $bounds['min']) {
            return CommandValidationResult::reject(
                CommandRejectionReason::ParameterOutOfRange,
                sprintf('%s is below the range this device accepts.', $this->humanize($capability->id)),
            );
        }

        if ($bounds['max'] !== null && $numeric > $bounds['max']) {
            return CommandValidationResult::reject(
                CommandRejectionReason::ParameterOutOfRange,
                sprintf('%s is above the range this device accepts.', $this->humanize($capability->id)),
            );
        }

        return CommandValidationResult::valid();
    }

    private function humanize(CapabilityId $id): string
    {
        return str_replace('_', ' ', $id->value);
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
