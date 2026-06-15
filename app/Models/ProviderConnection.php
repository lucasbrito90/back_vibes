<?php

declare(strict_types=1);

namespace App\Models;

use App\SmartHome\ConnectionStatus;
use Database\Factories\ProviderConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $provider
 * @property array $config
 * @property string $encrypted_credentials
 * @property string $status
 * @property Carbon|null $last_tested_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ProviderConnection extends Model
{
    /** @use HasFactory<ProviderConnectionFactory> */
    use HasFactory;

    /**
     * encrypted_credentials is always hidden — it must never appear in API
     * responses or toArray() output. Decryption happens only in the adapter
     * layer via decryptedCredentials().
     */
    protected $hidden = ['encrypted_credentials'];

    protected $fillable = [
        'name',
        'provider',
        'config',
        'encrypted_credentials',
        'status',
        'last_tested_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'last_tested_at' => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Credential helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Encrypt and store provider credentials.
     *
     * The raw credentials array (e.g. ['access_token' => '...']) is JSON-encoded
     * then encrypted with Laravel's Crypt facade before being written to the
     * encrypted_credentials column. The raw values are never stored in plaintext.
     *
     * Usage: $connection->setEncryptedCredentials(['access_token' => $token]);
     */
    public function setEncryptedCredentials(array $credentials): void
    {
        $this->encrypted_credentials = Crypt::encryptString(json_encode($credentials));
    }

    /**
     * Decrypt and return provider credentials.
     *
     * Returns the original credentials array (e.g. ['access_token' => '...']).
     * Only call this in the adapter layer — never expose the result in an API
     * resource or log output.
     *
     * @return array<string, mixed>
     */
    public function decryptedCredentials(): array
    {
        return json_decode(Crypt::decryptString($this->encrypted_credentials), true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    public function isConnected(): bool
    {
        return $this->status === ConnectionStatus::Connected->value;
    }
}
