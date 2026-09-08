<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One scene action execution row — trace_id is intentionally omitted (ADR-034 §6).
 */
class SceneActionExecutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scene_execution_id' => $this->scene_execution_id,
            'scene_id' => $this->scene_id,
            'scene_action_id' => $this->scene_action_id,
            'device_id' => $this->device_id,
            'provider' => $this->provider,
            'provider_connection_id' => $this->provider_connection_id,
            'action_type' => $this->action_type,
            'outcome' => $this->outcome,
            'failure_category' => $this->failure_category,
            'http_status_code' => $this->http_status_code,
            'duration_ms' => $this->duration_ms,
            'attempt' => $this->attempt,
            'executed_at' => $this->executed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
