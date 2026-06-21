<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @property-read array{status: string, requested_at: ?Carbon} $resource */
final class AdminAccessStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $requestedAt = $this->resource['requested_at'] ?? null;

        return [
            'status' => $this->resource['status'],
            'requested_at' => $requestedAt?->toIso8601String(),
        ];
    }
}
