<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSoundRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'duration_seconds' => ['required', 'integer', 'min:0'],
            'tags' => ['required', 'array', 'min:1'],
            'tags.*' => ['string', 'max:128'],
            'is_active' => ['sometimes', 'boolean'],
            'audio_file' => ['required', 'file'],
            'thumbnail_file' => ['required', 'file'],
            'file_url' => ['prohibited'],
            'thumbnail_url' => ['prohibited'],
            'audio_url' => ['prohibited'],
            'artwork_url' => ['prohibited'],
            'player_background_url' => ['prohibited'],
            'description' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('is_active')) {
            return;
        }

        $v = $this->input('is_active');
        if (is_bool($v)) {
            return;
        }

        $parsed = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $this->merge([
            'is_active' => $parsed ?? false,
        ]);
    }

    public function resolvedDuration(): ?int
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        if (array_key_exists('duration_seconds', $data) && $data['duration_seconds'] !== null) {
            return (int) $data['duration_seconds'];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function resolvedTags(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $tags = $data['tags'] ?? [];

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
