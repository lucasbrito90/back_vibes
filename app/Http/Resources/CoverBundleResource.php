<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoverBundleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'thumbnail_url' => $this->thumbnail_url,
            'artwork_url' => $this->artwork_url,
            'player_background_url' => $this->player_background_url,
            'category' => $this->category,
            'tags' => $this->tags ?? [],
            'is_active' => (bool) ($this->is_active ?? true),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
