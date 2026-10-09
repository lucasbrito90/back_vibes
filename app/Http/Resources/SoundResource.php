<?php

namespace App\Http\Resources;

use App\Models\SoundAudioRevision;
use App\Services\Audio\AudioRevisionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SoundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_url' => $this->file_url,
            /** Read-only alias for legacy clients; canonical field is {@see Sound::$file_url}. */
            'audio_url' => $this->file_url,
            /** Informational. The immutable `file_url` is the asset/cache identity; null until a version is verified. */
            'audio_version' => $this->audio_version,
            'audio_status' => $this->audio_status?->value,
            /** True once the current file came out of the distribution pipeline (false for adopted legacy files such as original WAVs). */
            'audio_optimized' => (bool) $this->audio_optimized,
            'audio_processing' => $this->audioProcessing(),
            'thumbnail_url' => $this->thumbnail_url,
            'category' => $this->category,
            'duration' => $this->duration,
            'duration_seconds' => $this->duration,
            'tags' => $this->tags ?? [],
            'is_active' => (bool) ($this->is_active ?? true),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Newer revision that has not been published (queued/processing/failed). Requires `latestAudioRevision`
     * to be eager loaded; null otherwise so list endpoints never trigger N+1 queries.
     *
     * @return array{status: string, version: int, error_code: string|null}|null
     */
    private function audioProcessing(): ?array
    {
        if (! $this->resource->relationLoaded('latestAudioRevision')) {
            return null;
        }

        /** @var SoundAudioRevision|null $revision */
        $revision = $this->resource->latestAudioRevision;
        if ($revision === null) {
            return null;
        }

        $unpublished = $revision->status !== AudioRevisionStatus::Ready
            && $revision->status !== AudioRevisionStatus::Superseded
            && $revision->version > (int) $this->audio_version;

        if (! $unpublished) {
            return null;
        }

        return [
            'status' => $revision->status->value,
            'version' => $revision->version,
            'error_code' => $revision->error_code,
        ];
    }
}
