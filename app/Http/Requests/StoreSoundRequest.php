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
            'file_url' => ['required', 'string', 'max:2048'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'duration' => ['nullable', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:128'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function resolvedDuration(): ?int
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        if (array_key_exists('duration_seconds', $data) && $data['duration_seconds'] !== null) {
            return (int) $data['duration_seconds'];
        }

        if (array_key_exists('duration', $data) && $data['duration'] !== null) {
            return (int) $data['duration'];
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
