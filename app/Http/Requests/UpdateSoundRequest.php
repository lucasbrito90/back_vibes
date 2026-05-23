<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSoundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'file_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'thumbnail_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:128'],
            'is_active' => ['sometimes', 'boolean'],
            'audio_url' => ['prohibited'],
            'artwork_url' => ['prohibited'],
            'player_background_url' => ['prohibited'],
            'description' => ['prohibited'],
        ];
    }

    public function resolvedDuration(): ?int
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        if (array_key_exists('duration_seconds', $data)) {
            return $data['duration_seconds'] === null ? null : (int) $data['duration_seconds'];
        }

        if (array_key_exists('duration', $data)) {
            return $data['duration'] === null ? null : (int) $data['duration'];
        }

        return null;
    }

    /**
     * @return list<string>|null Null when tags key absent from payload.
     */
    public function resolvedTags(): ?array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        if (! array_key_exists('tags', $data)) {
            return null;
        }

        $tags = $data['tags'];

        if (! is_array($tags)) {
            return [];
        }

        /** @var list<string> */
        return array_values(array_filter(array_map(
            static fn (mixed $t): string => is_string($t) ? trim($t) : '',
            $tags,
        ), static fn (string $s): bool => $s !== ''));
    }
}
