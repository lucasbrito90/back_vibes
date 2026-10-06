<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_connection_id' => $this->provider_connection_id,
            'name' => $this->name,
            'type' => $this->type,
            'provider' => $this->provider,
            'provider_device_id' => $this->provider_device_id,
            'status' => $this->status,
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            // Both of these are maps, and an empty PHP array encodes as `[]`
            // rather than `{}` — so the wire type would otherwise change with
            // cardinality. `null` is preserved: for capabilities it means
            // "never derived" (unknown), which a client must not confuse with
            // `{}`, "declares none". Only the empty-map case is rewritten.
            'metadata' => $this->metadata === [] ? (object) [] : $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'capabilities' => $this->capabilities === [] ? (object) [] : $this->capabilities,
            // DEV-01: canonical functional state (ADR-037 §6), nested under the
            // Device as card 166's model requires rather than on an endpoint of
            // its own, so there is one read contract for state and not two.
            //
            // Carries values/read_at/freshness ONLY. No provider_device_id, no
            // provider-native attributes, no SDK or entity vocabulary — those
            // stop at the adapter (ADR-037 §1, §7). The sibling keys above that
            // DO leak provider internals are a pre-existing boundary defect
            // explicitly owned by card 166, untouched here.
            'state' => $this->stateSnapshot()->toArray(),
        ];
    }
}
