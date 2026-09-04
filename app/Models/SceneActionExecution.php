<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SceneActionExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per scene action execution attempt (ADR-034).
 *
 * @property int $id
 * @property string $scene_execution_id
 * @property int $scene_id
 * @property int|null $scene_action_id
 * @property int $device_id
 * @property string $provider
 * @property int $provider_connection_id
 * @property string $action_type
 * @property string $outcome
 * @property string|null $failure_category
 * @property int|null $http_status_code
 * @property int|null $duration_ms
 * @property string|null $trace_id
 * @property int $attempt
 * @property Carbon $executed_at
 * @property Carbon $created_at
 */
#[Fillable([
    'scene_execution_id',
    'scene_id',
    'scene_action_id',
    'device_id',
    'provider',
    'provider_connection_id',
    'action_type',
    'outcome',
    'failure_category',
    'http_status_code',
    'duration_ms',
    'trace_id',
    'attempt',
    'executed_at',
    'created_at',
])]
final class SceneActionExecution extends Model
{
    /** @use HasFactory<SceneActionExecutionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'scene_execution_id' => 'string',
            'http_status_code' => 'integer',
            'duration_ms' => 'integer',
            'attempt' => 'integer',
            'executed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function sceneAction(): BelongsTo
    {
        return $this->belongsTo(SceneAction::class);
    }
}
