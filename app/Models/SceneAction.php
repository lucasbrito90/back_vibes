<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SceneActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $scene_id
 * @property int $device_id
 * @property string $action_type
 * @property array|null $parameters
 * @property int $sort_order
 * @property int $delay_seconds
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'scene_id',
    'device_id',
    'action_type',
    'parameters',
    'delay_seconds',
    'sort_order',
])]
final class SceneAction extends Model
{
    /** @use HasFactory<SceneActionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'sort_order' => 'integer',
            'delay_seconds' => 'integer',
        ];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
