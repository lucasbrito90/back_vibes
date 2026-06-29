<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for a push token.
 *
 * Intentionally omits the raw token field — it is always hidden per ADR-021.
 * Exposes token_preview (safe truncated form) for client-side debugging only.
 * token_hash is intentionally excluded from the API response (internal logs only).
 *
 * References: ADR-021, spec.md §5.
 */
final class PushTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'provider' => $this->provider,
            'device_id' => $this->device_id,
            'app_version' => $this->app_version,
            'device_model' => $this->device_model,
            'is_active' => $this->is_active,
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'token_preview' => $this->resource->tokenPreview(),
        ];
    }
}
