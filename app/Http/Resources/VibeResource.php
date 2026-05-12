<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VibeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Fallback chain: context-specific field → thumbnail_url → null.
        // This keeps backward compatibility while new uploads populate the
        // dedicated fields independently.
        $thumb = $this->thumbnail_url;

        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'description'          => $this->description,
            // Legacy field — kept for backward compat. Use the specific fields below.
            'thumbnail_url'        => $thumb,
            // Context-specific fields with thumbnail_url fallback.
            'card_image_url'       => $this->card_image_url       ?? $thumb,
            'player_background_url'=> $this->player_background_url ?? $thumb,
            'artwork_url'          => $this->artwork_url           ?? $thumb,
            'is_active'            => $this->is_active,
            'sounds_count'         => (int) ($this->sounds_count ?? 0),
            'created_at'           => $this->created_at?->toISOString(),
            'updated_at'           => $this->updated_at?->toISOString(),
        ];
    }
}
