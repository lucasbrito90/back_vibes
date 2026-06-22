<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PushTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * FCM (or future provider) device registration token for push delivery.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $platform
 * @property string $provider
 * @property string|null $device_id
 * @property string|null $app_version
 * @property string|null $device_model
 * @property bool $is_active
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class PushToken extends Model
{
    /** @use HasFactory<PushTokenFactory> */
    use HasFactory;

    /**
     * The raw FCM token must never appear in API responses or casual serialization.
     * Use tokenPreview() or tokenHash() for logs and debugging.
     */
    protected $hidden = ['token'];

    protected $fillable = [
        'token',
        'platform',
        'provider',
        'device_id',
        'app_version',
        'device_model',
        'is_active',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Token privacy helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Safe short preview for logs and admin tooling — never the full token.
     */
    public function tokenPreview(): string
    {
        $token = $this->token ?? '';
        $length = strlen($token);

        if ($length === 0) {
            return '';
        }

        if ($length <= 10) {
            return str_repeat('*', min($length, 6));
        }

        return substr($token, 0, 6).'...'.substr($token, -4);
    }

    /**
     * SHA-256 hash of the token for safe log correlation without exposing the secret.
     */
    public function tokenHash(): string
    {
        return hash('sha256', $this->token ?? '');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  Builder<PushToken>  $query
     * @return Builder<PushToken>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
