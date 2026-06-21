<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_connection_id' => $this->provider_connection_id,
            'name' => $this->name,
            'type' => $this->type,
            'provider' => $this->provider,
            'provider_device_id' => $this->provider_device_id,
            'status' => $this->status,
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
