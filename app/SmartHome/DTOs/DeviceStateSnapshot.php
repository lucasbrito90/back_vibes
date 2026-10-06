<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

use App\SmartHome\Canonical\DeviceState;
use App\SmartHome\DeviceStateFreshness;
use DateTimeInterface;

/**
 * A device's functional state as the API reports it: the canonical values plus
 * how much they can be trusted (DEV-01).
 *
 * `DeviceState` alone cannot express "no value available" honestly — it
 * requires a `read_at` by construction, so `DeviceState::unknown()` stamps a
 * read time for a read that never produced anything. This wrapper keeps the
 * canonical object for the known cases and carries null for the unknown one,
 * so a device whose state was never read reports no timestamp rather than a
 * misleading one.
 *
 * This is the shape the Device contract exposes under `state`. It deliberately
 * carries NO provider identifiers, NO provider-native attributes and NO SDK
 * names — those die at the adapter boundary (ADR-037 §1, §7). What crosses is
 * the canonical capability vocabulary and nothing else.
 */
final readonly class DeviceStateSnapshot
{
    private function __construct(
        public ?DeviceState $state,
        public DeviceStateFreshness $freshness,
    ) {}

    /**
     * No functional value is available — never read, read failed with nothing
     * previously known, or a stored value that can no longer be expressed in
     * the current capability contract.
     */
    public static function unknown(): self
    {
        return new self(null, DeviceStateFreshness::Unknown);
    }

    /** A value observed within the TTL. */
    public static function fresh(DeviceState $state): self
    {
        return new self($state, DeviceStateFreshness::Fresh);
    }

    /**
     * A known value that is no longer asserted as current, because the last
     * observation aged past the TTL or the most recent read attempt failed.
     */
    public static function stale(DeviceState $state): self
    {
        return new self($state, DeviceStateFreshness::Stale);
    }

    /**
     * Builds a snapshot from a state whose values may be empty.
     *
     * An empty read is not a failure, but it carries no value — a device that
     * reports nothing canonical is `unknown`, not "known to be empty". Folding
     * that here keeps every caller from having to remember it.
     */
    public static function of(DeviceState $state, bool $isFresh): self
    {
        if ($state->isEmpty()) {
            return self::unknown();
        }

        return $isFresh ? self::fresh($state) : self::stale($state);
    }

    public function isUnknown(): bool
    {
        return $this->freshness === DeviceStateFreshness::Unknown;
    }

    /**
     * `values` is cast to an object so the wire type does not change with
     * cardinality: `DeviceState::$values` is a map keyed by capability id, and
     * `json_encode` renders an empty PHP array as `[]`, so a device with no
     * values shipped a JSON array where every populated device ships an object.
     * A client typing the field as a map then fails on exactly the stateless
     * devices — and the unknown case is the common one, since `?? []` is taken
     * whenever state was never read.
     *
     * @return array{values: object|array<string, mixed>, read_at: string|null, freshness: string}
     */
    public function toArray(): array
    {
        return [
            'values' => $this->state?->values ?: (object) [],
            'read_at' => $this->state?->readAt->format(DateTimeInterface::ATOM),
            'freshness' => $this->freshness->value,
        ];
    }
}
