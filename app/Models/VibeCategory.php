<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['slug', 'names', 'sort_order', 'is_active'])]
final class VibeCategory extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'names' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<VibeCategory>  $query
     * @return Builder<VibeCategory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return BelongsToMany<Vibe, $this> */
    public function vibes(): BelongsToMany
    {
        return $this->belongsToMany(Vibe::class, 'vibe_vibe_categories');
    }

    /** @return BelongsToMany<PresetVibe, $this> */
    public function presetVibes(): BelongsToMany
    {
        return $this->belongsToMany(PresetVibe::class, 'preset_vibe_vibe_categories');
    }
}
