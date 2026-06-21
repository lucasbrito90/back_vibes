<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCoverBundleRequest extends FormRequest
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
            'description' => ['sometimes', 'nullable', 'string'],
            'thumbnail_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'artwork_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'player_background_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
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
