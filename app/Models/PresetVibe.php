<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'description',
    'cover_bundle_id',
    'category',
    'tags',
    'is_active',
])]
final class PresetVibe extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function coverBundle(): BelongsTo
    {
        return $this->belongsTo(CoverBundle::class);
    }

    /** Sound layers — eager-load `sound` for catalog metadata. */
    /** @return HasMany<PresetVibeSound, $this> */
    public function presetVibeSounds(): HasMany
    {
        return $this->hasMany(PresetVibeSound::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsToMany<Sound, $this> */
    public function sounds(): BelongsToMany
    {
        return $this->belongsToMany(Sound::class, 'preset_vibe_sounds')
            ->withPivot([
                'id',
                'volume',
                'loop',
                'play_mode',
                'sort_order',
                'repeat_interval_seconds',
                'start_offset_seconds',
                'play_duration_seconds',
            ])
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderByPivot('id');
    }
}
