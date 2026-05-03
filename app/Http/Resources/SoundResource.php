<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SoundResource extends JsonResource
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
            'created_at'    => $this->created_at?->toISOString(),
        ];
    }
}
