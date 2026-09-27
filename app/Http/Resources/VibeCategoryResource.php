<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VibeCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'names' => $this->names ?? [],
            'sort_order' => (int) $this->sort_order,
            $this->mergeWhen($request->user()?->isAdminApproved() ?? false, [
                'is_active' => (bool) ($this->is_active ?? true),
            ]),
        ];
    }
}
