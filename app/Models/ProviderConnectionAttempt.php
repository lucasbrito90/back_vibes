<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records a single connectivity attempt for a provider connection (PRV-02).
 *
 * @property int $id
 * @property int $provider_connection_id
 * @property string $source 'server' | 'client'
 * @property string $outcome 'success' | 'failure_host' | 'failure_credentials'
 * @property string|null $failure_reason Brief text — MUST NOT contain credential values.
 * @property int|null $latency_ms
 * @property Carbon $observed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ProviderConnectionAttempt extends Model
{
    protected $fillable = [
        'provider_connection_id',
        'source',
        'outcome',
        'failure_reason',
        'latency_ms',
        'observed_at',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
        'latency_ms' => 'integer',
    ];

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class);
    }
}
