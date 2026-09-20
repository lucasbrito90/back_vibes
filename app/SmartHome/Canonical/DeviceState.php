<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

use App\SmartHome\Canonical\Exceptions\InvalidCapabilityDefinitionException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * A device's functional state, keyed by the canonical capability vocabulary
 * (ADR-037 §6, CSDM-05).
 *
 * Two things this fixes, both named in the ADR's own audit.
 *
 * First, connectivity and functional state were the same word. "Status" meant
 * both "is this device reachable" and "is the lamp on" — so a device that was
 * online with its light off looked, in places, like a device that was off.
 * `Device.connectivity` remains the sole authority on reachability and is NOT
 * repeated here; this object carries only what the ADR calls functional state.
 *
 * Second, state was provider-raw: `DeviceStatusResult.raw_state` (a Home
 * Assistant string) plus `attributes` (a Home Assistant dictionary), both
 * explicitly un-normalised — their own names said so. A client reading state
 * and a client sending a command spoke two different vocabularies about the
 * same device. Here they share one: `values` is keyed by the same CapabilityId
 * that commands use, so `power` means the same thing on the way in and out.
 *
 * Read-only capabilities are what make this more than a rename. `energy` and
 * `current_temperature` have no operations at all (ADR-037 §3) — they are read
 * into `values` and never commanded. Before this, representing them required
 * inventing an ActionType for something that can never be acted on.
 */
final readonly class DeviceState
{
    /**
     * @param  array<string, mixed>  $values  canonical capability id => value
     */
    private function __construct(
        public array $values,
        public DateTimeImmutable $readAt,
    ) {}

    /**
     * Builds state from canonical values, rejecting anything the contract does
     * not recognise.
     *
     * Validation is deliberate rather than permissive: a provider mapper that
     * reports an unknown key, or a `brightness` outside 0-100, has a defect,
     * and storing it would push the discovery of that defect to whoever reads
     * the value later.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, Capability>  $capabilities  the device's declared capabilities, keyed by id
     *
     * @throws InvalidCapabilityDefinitionException
     */
    public static function of(array $values, array $capabilities, ?DateTimeInterface $readAt = null): self
    {
        $validated = [];

        foreach ($values as $key => $value) {
            $id = is_string($key) ? CapabilityId::tryFrom($key) : null;

            if ($id === null) {
                throw new InvalidCapabilityDefinitionException(
                    sprintf(
                        'Device state key [%s] is not a canonical capability id.',
                        is_scalar($key) ? (string) $key : gettype($key),
                    ),
                );
            }

            $capability = $capabilities[$id->value] ?? null;

            if ($capability === null) {
                throw new InvalidCapabilityDefinitionException(
                    sprintf(
                        'Device state reports [%s], which this device does not declare as a capability.',
                        $id->value,
                    ),
                );
            }

            self::assertValueMatchesConstraint($capability, $value);

            $validated[$id->value] = $value;
        }

        return new self(
            $validated,
            $readAt instanceof DateTimeImmutable
                ? $readAt
                : DateTimeImmutable::createFromInterface($readAt ?? new DateTimeImmutable),
        );
    }

    /** State for a device whose values could not be read at all. */
    public static function unknown(?DateTimeInterface $readAt = null): self
    {
        return new self(
            [],
            $readAt instanceof DateTimeImmutable
                ? $readAt
                : DateTimeImmutable::createFromInterface($readAt ?? new DateTimeImmutable),
        );
    }

    /** The value of one capability, or null when this read did not include it. */
    public function value(CapabilityId $id): mixed
    {
        return $this->values[$id->value] ?? null;
    }

    public function has(CapabilityId $id): bool
    {
        return array_key_exists($id->value, $this->values);
    }

    /** Whether this read produced no values at all. */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * Whether this read is older than the given age in seconds.
     *
     * The TTL is the caller's to choose, deliberately. ADR-037 §6 leaves
     * persistence and caching to whoever implements each capability family —
     * a thermostat's temperature ages differently from a lamp's on/off — so
     * baking one number in here would be inventing a policy the ADR declined
     * to set. What this class guarantees is that the question is answerable
     * at all, which `raw_state` never was: it carried no read time.
     */
    public function isOlderThan(int $seconds, ?DateTimeInterface $now = null): bool
    {
        $reference = $now?->getTimestamp() ?? time();

        return ($reference - $this->readAt->getTimestamp()) > $seconds;
    }

    /** @return array{values: array<string, mixed>, read_at: string} */
    public function toArray(): array
    {
        return [
            'values' => $this->values,
            'read_at' => $this->readAt->format(DateTimeInterface::ATOM),
        ];
    }

    private static function assertValueMatchesConstraint(Capability $capability, mixed $value): void
    {
        $constraint = $capability->constraints;

        if ($constraint instanceof BooleanConstraint && ! is_bool($value)) {
            throw new InvalidCapabilityDefinitionException(
                sprintf('State for [%s] must be true or false.', $capability->id->value),
            );
        }

        if ($constraint instanceof EnumConstraint && ! in_array($value, $constraint->allowedValues, true)) {
            throw new InvalidCapabilityDefinitionException(
                sprintf(
                    'State for [%s] must be one of: %s.',
                    $capability->id->value,
                    implode(', ', $constraint->allowedValues),
                ),
            );
        }

        if (! $constraint instanceof NumberConstraint) {
            return;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidCapabilityDefinitionException(
                sprintf('State for [%s] must be a number.', $capability->id->value),
            );
        }

        $numeric = (float) $value;

        // A null bound means the provider never declared one (ADR-037 §4) —
        // the check is skipped, not defaulted, exactly as in command
        // validation. A thermostat with no documented sensor range must still
        // be able to report its temperature.
        if ($constraint->min !== null && $numeric < $constraint->min) {
            throw new InvalidCapabilityDefinitionException(
                sprintf('State for [%s] is below its declared minimum.', $capability->id->value),
            );
        }

        if ($constraint->max !== null && $numeric > $constraint->max) {
            throw new InvalidCapabilityDefinitionException(
                sprintf('State for [%s] is above its declared maximum.', $capability->id->value),
            );
        }
    }
}
