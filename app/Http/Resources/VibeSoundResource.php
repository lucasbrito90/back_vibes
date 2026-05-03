<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VibeSoundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'file_url'      => $this->file_url,
            'thumbnail_url' => $this->thumbnail_url,
            'category'      => $this->category,
            'duration'      => $this->duration,
            'volume'                  => $this->pivot->volume,
            'loop'                    => (bool) $this->pivot->loop,
            'sort_order'              => $this->pivot->sort_order,
            'play_mode'               => $this->pivot->play_mode ?? 'loop',
            'repeat_interval_seconds' => $this->pivot->repeat_interval_seconds,
            'start_offset_seconds'    => $this->pivot->start_offset_seconds,
            'play_duration_seconds'   => $this->pivot->play_duration_seconds,
            'fade_in_seconds'         => $this->pivot->fade_in_seconds,
            'fade_out_seconds'        => $this->pivot->fade_out_seconds,
        ];
    }
}
