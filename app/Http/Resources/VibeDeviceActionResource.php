<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VibeDeviceActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vibe_id' => $this->vibe_id,
            'device_id' => $this->device_id,
            'action_type' => $this->action_type,
            'parameters' => $this->parameters,
            'sort_order' => $this->sort_order,
            'delay_seconds' => $this->delay_seconds,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'device' => $this->whenLoaded('device', fn () => [
                'id' => $this->device->id,
                'name' => $this->device->name,
                'type' => $this->device->type,
                'provider' => $this->device->provider,
                'status' => $this->device->status,
                'provider_device_id' => $this->device->provider_device_id,
            ]),
        ];
    }
}
