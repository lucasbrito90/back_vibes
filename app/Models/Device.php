<?php

declare(strict_types=1);

namespace App\Models;

use App\SmartHome\DeviceStatus;
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
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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
}
