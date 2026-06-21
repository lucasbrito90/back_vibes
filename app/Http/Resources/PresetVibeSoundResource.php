<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PresetVibeSound;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PresetVibeSoundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PresetVibeSound $layer */
        $layer = $this->resource;

        return [
            'id' => $layer->id,
            'sound_id' => $layer->sound_id,
            'sound' => new SoundResource($this->whenLoaded('sound')),
            'volume' => $layer->volume,
            'loop' => (bool) $layer->loop,
            'sort_order' => $layer->sort_order,
            'play_mode' => $layer->play_mode,
            'repeat_interval_seconds' => $layer->repeat_interval_seconds,
            'start_offset_seconds' => $layer->start_offset_seconds,
            'start_delay_seconds' => $layer->start_offset_seconds,
            'play_duration_seconds' => $layer->play_duration_seconds,
            'duration_seconds' => $layer->play_duration_seconds,
            'created_at' => $layer->created_at?->toISOString(),
            'updated_at' => $layer->updated_at?->toISOString(),
        ];
    }
}
