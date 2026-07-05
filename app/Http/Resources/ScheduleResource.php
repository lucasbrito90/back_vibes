<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vibe_id' => $this->vibe_id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'start_time' => $this->start_time?->toISOString(),
            'recurrence_type' => $this->recurrence_type,
            'recurrence_config' => $this->recurrence_config,
            'is_enabled' => $this->is_enabled,
            'next_run_at' => $this->next_run_at?->toISOString(),
            'last_run_at' => $this->last_run_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'vibe_name' => $this->vibe?->name,
            'device_actions_count' => (int) ($this->vibe?->device_actions_count ?? 0),
            'has_device_actions' => (bool) ($this->vibe?->device_actions_count ?? 0),
        ];
    }
}
