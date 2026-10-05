<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\SmartHome\Canonical\LegacyCapabilitiesReader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BFF Device Detail contract (card 166, ADR-037).
 *
 * Projects the canonical Device model to a stable, provider-agnostic payload
 * for ixora-app. Exposes identity, connectivity, capabilities, and state —
 * nothing more.
 *
 * No provider internals cross this boundary: no provider_device_id, no
 * entity_id, no metadata, no credentials, no SDK vocabulary. Two devices from
 * different providers that share the same capabilities produce the same payload
 * shape (CSDM §1, §6).
 *
 * Capabilities are serialized in canonical CSDM v1 format (ADR-037 §2–§3).
 * Each entry carries id, access, operations, and constraints — the frontend
 * derives all controls from this; the backend never prescribes a widget,
 * layout, or rendering hint.
 *
 * State is consumed directly from DEV-01 (DeviceStateSnapshot::toArray()).
 *
 * This resource is used only by DeviceController::show. Other actions
 * (index/store/update/destroy) continue to use DeviceResource so their
 * existing contracts are not disturbed.
 */
class DeviceDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identity — Ixora surrogate key, display name, display category.
            // No provider_device_id, no entity_id, no provider slug.
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,

            // Connectivity — ADR-037 §2.1 DeviceStatus (online/offline/unknown).
            // Renamed from "status" to "connectivity" to match the CSDM field name
            // and to prevent confusion with HTTP status codes on the client side.
            'connectivity' => $this->status,

            // Capabilities — canonical CSDM v1 map.
            // Each capability: id, access (read|write|read_write), operations [],
            // and constraints (number/enum/boolean) — enough for the frontend to
            // derive valid controls and know what commands to send to DEV-02.
            // Null when capabilities were never synced.
            'capabilities' => $this->resolveCanonicalCapabilities(),

            // State — functional values, when last observed, and how fresh they are.
            // Provided by DEV-01 (Device::stateSnapshot / DeviceStateService).
            // freshness: "fresh" | "stale" | "unknown"
            'state' => $this->stateSnapshot()->toArray(),
        ];
    }

    /** @return array<string, array<string, mixed>>|null */
    private function resolveCanonicalCapabilities(): ?array
    {
        $doc = (new LegacyCapabilitiesReader)->read($this->capabilities);

        if ($doc === null) {
            return null;
        }

        $result = [];

        foreach ($doc->capabilities as $id => $capability) {
            $result[$id] = $capability->toArray();
        }

        return $result !== [] ? $result : null;
    }
}
