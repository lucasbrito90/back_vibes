<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SoundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_url' => $this->file_url,
            /** Read-only alias for legacy clients; canonical field is {@see Sound::$file_url}. */
            'audio_url' => $this->file_url,
            'thumbnail_url' => $this->thumbnail_url,
            'category' => $this->category,
            'duration' => $this->duration,
            'duration_seconds' => $this->duration,
            'tags' => $this->tags ?? [],
            'is_active' => (bool) ($this->is_active ?? true),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
