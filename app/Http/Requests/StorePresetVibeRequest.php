<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePresetVibeRequest extends FormRequest
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
            'description' => ['nullable', 'string'],
            'cover_bundle_id' => ['nullable', 'integer', 'exists:cover_bundles,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
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
