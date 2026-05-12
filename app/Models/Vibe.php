<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'description', 'thumbnail_url', 'card_image_url', 'player_background_url', 'artwork_url', 'is_active'])]
final class Vibe extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sounds(): BelongsToMany
    {
        return $this->belongsToMany(Sound::class, 'vibe_sounds')
            ->withPivot([
                'volume',
                'loop',
                'sort_order',
                'play_mode',
                'repeat_interval_seconds',
                'start_offset_seconds',
                'play_duration_seconds',
                'fade_in_seconds',
                'fade_out_seconds',
            ])
            ->using(VibeSound::class)
            ->orderByPivot('sort_order');
    }

    public function deviceActions(): HasMany
    {
        return $this->hasMany(VibeDeviceAction::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}
