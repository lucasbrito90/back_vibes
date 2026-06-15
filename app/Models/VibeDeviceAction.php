<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VibeDeviceActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vibe_id
 * @property int $device_id
 * @property string $action_type
 * @property array|null $parameters
 * @property int $sort_order
 * @property int $delay_seconds
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class VibeDeviceAction extends Model
{
    /** @use HasFactory<VibeDeviceActionFactory> */
    use HasFactory;

    protected $fillable = [
        'vibe_id',
        'device_id',
        'action_type',
        'parameters',
        'sort_order',
        'delay_seconds',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'sort_order' => 'integer',
            'delay_seconds' => 'integer',
        ];
    }

    public function vibe(): BelongsTo
    {
        return $this->belongsTo(Vibe::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
