<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'description', 'is_active'])]
class Vibe extends Model
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
            ->withPivot(['volume', 'loop', 'sort_order'])
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
