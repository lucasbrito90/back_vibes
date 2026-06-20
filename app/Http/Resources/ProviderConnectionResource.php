<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exposes safe provider connection fields.
 *
 * encrypted_credentials / access_token are NEVER included — they are hidden
 * on the model and must never appear in API responses (spec.md §8 Security).
 */
class ProviderConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'config' => $this->config,
            'status' => $this->status,
            'last_tested_at' => $this->last_tested_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
