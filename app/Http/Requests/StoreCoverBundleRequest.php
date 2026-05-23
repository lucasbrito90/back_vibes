<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Storage\UploadAssetValidator;
use Illuminate\Foundation\Http\FormRequest;

class StoreCoverBundleRequest extends FormRequest
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
        $maxImageKb = intdiv(UploadAssetValidator::IMAGE_MAX_BYTES, 1024);

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'tags' => ['required', 'array', 'min:1'],
            'tags.*' => ['string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'thumbnail_url' => ['prohibited'],
            'artwork_url' => ['prohibited'],
            'player_background_url' => ['prohibited'],
            'thumbnail_file' => ['required', 'file', 'max:'.$maxImageKb],
            'artwork_file' => ['required', 'file', 'max:'.$maxImageKb],
            'player_background_file' => ['required', 'file', 'max:'.$maxImageKb],
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

    /**
     * @return array{name: string, description: ?string, category: string, tags: list<string>, is_active: bool}
     */
    public function resolvedMetadata(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $description = array_key_exists('description', $data) ? $data['description'] : null;

        return [
            'name' => (string) ($data['name'] ?? ''),
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'category' => trim((string) ($data['category'] ?? '')),
            'tags' => $this->resolvedTags(),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }
}
