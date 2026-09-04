<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\SmartHome\SceneExecutionAggregateState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One paginated execution event summary (ADR-034 §6 list endpoint).
 *
 * @mixin object{
 *     scene_execution_id: string,
 *     count_success: int|string,
 *     count_non_success: int|string,
 *     count_total: int|string,
 *     executed_at: string|null,
 *     state: SceneExecutionAggregateState
 * }
 */
class SceneExecutionEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'scene_execution_id' => $this->scene_execution_id,
            'state' => $this->state->value,
            'count_success' => (int) $this->count_success,
            'count_non_success' => (int) $this->count_non_success,
            'count_total' => (int) $this->count_total,
            'executed_at' => $this->executed_at,
        ];
    }
}
