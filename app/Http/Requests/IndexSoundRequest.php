<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Queries\SoundCatalogQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSoundRequest extends FormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.SoundCatalogQuery::MAX_PER_PAGE],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:128'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['active', 'inactive', 'all'])],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(SoundCatalogQuery::SORTABLE_COLUMNS)],
            'direction' => ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function searchTerm(): ?string
    {
        if (! $this->has('search')) {
            return null;
        }

        $value = trim((string) $this->input('search'));

        return $value === '' ? null : $value;
    }

    public function categoryFilter(): ?string
    {
        if (! $this->has('category')) {
            return null;
        }

        $value = trim((string) $this->input('category'));

        return $value === '' ? null : $value;
    }

    public function tagFilter(): ?string
    {
        if (! $this->has('tag')) {
            return null;
        }

        $value = trim((string) $this->input('tag'));

        return $value === '' ? null : $value;
    }

    public function statusFilter(): ?string
    {
        if (! $this->has('status')) {
            return null;
        }

        $value = trim((string) $this->input('status'));

        if ($value === '' || $value === 'all') {
            return null;
        }

        return $value;
    }

    public function sortColumn(): string
    {
        $sort = $this->input('sort');

        if (is_string($sort) && in_array($sort, SoundCatalogQuery::SORTABLE_COLUMNS, true)) {
            return $sort;
        }

        return 'name';
    }

    public function sortDirection(): string
    {
        $direction = $this->input('direction');

        return $direction === 'desc' ? 'desc' : 'asc';
    }
}
