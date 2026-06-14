<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleExecutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_id' => $this->schedule_id,
            'occurrence_key' => $this->occurrence_key,
            'scheduled_for' => $this->scheduled_for?->toISOString(),
            'executed_at' => $this->executed_at?->toISOString(),
            'status' => $this->status,
            'log' => $this->log,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
