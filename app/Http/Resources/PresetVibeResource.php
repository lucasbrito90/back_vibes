<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PresetVibe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PresetVibeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PresetVibe $preset */
        $preset = $this->resource;

        return [
            'id' => $preset->id,
            'name' => $preset->name,
            'description' => $preset->description,
            'cover_bundle_id' => $preset->cover_bundle_id,
            'cover_bundle' => $this->when(
                $preset->relationLoaded('coverBundle'),
                fn () => $preset->coverBundle !== null
                    ? new CoverBundleResource($preset->coverBundle)
                    : null,
            ),
            'category' => $preset->category,
            'tags' => $preset->tags ?? [],
            'is_active' => (bool) ($preset->is_active ?? true),
            'sounds' => PresetVibeSoundResource::collection($this->whenLoaded('presetVibeSounds')),
            'created_at' => $preset->created_at?->toISOString(),
            'updated_at' => $preset->updated_at?->toISOString(),
        ];
    }
}
