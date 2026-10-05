<?php

declare(strict_types=1);

namespace App\Models;

use App\SmartHome\Canonical\DeviceState;
use App\SmartHome\Canonical\Exceptions\InvalidCapabilityDefinitionException;
use App\SmartHome\Canonical\LegacyCapabilitiesReader;
use App\SmartHome\DeviceStatus;
use App\SmartHome\DTOs\DeviceStateSnapshot;
use App\SmartHome\Services\DeviceStateService;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $provider_connection_id
 * @property string $name
 * @property string|null $type IXORA-normalised category (DeviceType) when set; nullable for manual create
 * @property string $provider
 * @property string $provider_device_id
 * @property string $status
 * @property array|null $metadata
 * @property array<string, array<string, mixed>>|null $capabilities ADR-033 capability map; null = unknown
 * @property array<string, mixed>|null $state Canonical DeviceState values (ADR-037 §6); null = never read
 * @property Carbon|null $state_read_at When $state was observed; NOT interchangeable with last_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider_connection_id',
        'name',
        'type',
        'provider',
        'provider_device_id',
        'status',
        'metadata',
        'capabilities',
        'state',
        'state_read_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'capabilities' => 'array',
            'state' => 'array',
            'state_read_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    public function isOnline(): bool
    {
        return $this->status === DeviceStatus::Online->value;
    }

    /**
     * This device's functional state as the contract reports it (DEV-01, ADR-037 §6).
     *
     * Pure interpretation of two already-loaded columns — it contacts no
     * provider and performs no query, which is what makes it safe for the
     * device list. Refreshing from the provider is
     * {@see DeviceStateService::refreshIfStale()}.
     *
     * Stored values are re-validated against the device's CURRENT declared
     * capabilities rather than trusted. A value that was canonical when written
     * can stop being expressible later — a re-sync that drops `brightness`
     * leaves a stored brightness the contract no longer admits — and the
     * honest answer then is `unknown`, not a value no consumer can interpret.
     * Degrading beats throwing here: the alternative is a device list that
     * 500s because one row aged badly.
     *
     * Connectivity is deliberately absent. `status` is the sole authority on
     * reachability (ADR-037 §6) and is never duplicated into state — a device
     * that is offline may still have a last-known functional value, and the two
     * questions stay separate.
     */
    public function stateSnapshot(?int $ttlSeconds = null): DeviceStateSnapshot
    {
        if ($this->state === null || $this->state_read_at === null) {
            return DeviceStateSnapshot::unknown();
        }

        $declared = (new LegacyCapabilitiesReader)->read($this->capabilities);

        if ($declared === null) {
            return DeviceStateSnapshot::unknown();
        }

        try {
            $state = DeviceState::of(
                $this->state,
                $declared->capabilities,
                $this->state_read_at->toDateTimeImmutable(),
            );
        } catch (InvalidCapabilityDefinitionException) {
            return DeviceStateSnapshot::unknown();
        }

        $ttl = $ttlSeconds ?? DeviceStateService::DEFAULT_TTL_SECONDS;

        return DeviceStateSnapshot::of($state, ! $state->isOlderThan($ttl));
    }
}
