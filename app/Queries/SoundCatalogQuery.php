<?php

declare(strict_types=1);

namespace App\Queries;

use App\Http\Requests\IndexSoundRequest;
use App\Models\Sound;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class SoundCatalogQuery
{
    public const SORTABLE_COLUMNS = ['name', 'category', 'created_at', 'duration', 'is_active'];

    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly IndexSoundRequest $request,
        private readonly ?Authenticatable $actor = null,
    ) {}

    /**
     * @return Builder<Sound>
     */
    public function build(): Builder
    {
        $query = Sound::query();
        $this->applyFilters($query);
        $this->applySort($query);

        return $query;
    }

    public function wantsPagination(): bool
    {
        return $this->request->has('page') || $this->request->has('per_page');
    }

    public function perPage(): int
    {
        return (int) $this->request->input('per_page', self::DEFAULT_PER_PAGE);
    }

    /**
     * @return list<string>
     */
    public function availableCategories(): array
    {
        return Sound::query()
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Sound>  $query
     */
    private function applyFilters(Builder $query): void
    {
        $search = $this->request->searchTerm();
        if ($search !== null) {
            $escaped = addcslashes($search, '%_\\');
            $query->where('name', 'like', '%'.$escaped.'%');
        }

        $category = $this->request->categoryFilter();
        if ($category !== null) {
            $query->where('category', $category);
        }

        $tag = $this->request->tagFilter();
        if ($tag !== null) {
            $query->whereJsonContains('tags', $tag);
        }

        $status = $this->request->statusFilter();
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            if ($this->canViewInactive()) {
                $query->where('is_active', false);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
    }

    /**
     * @param  Builder<Sound>  $query
     */
    private function applySort(Builder $query): void
    {
        $sort = $this->request->sortColumn();
        $direction = $this->request->sortDirection();

        $query->orderBy($sort, $direction);

        if ($sort !== 'name') {
            $query->orderBy('name', 'asc');
        }
    }

    private function canViewInactive(): bool
    {
        $user = $this->actor;

        if (! $user instanceof User) {
            return false;
        }

        return Gate::forUser($user)->allows('viewInactive', Sound::class);
    }
}
