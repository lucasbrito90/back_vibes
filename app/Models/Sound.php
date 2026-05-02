<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'file_url', 'category', 'duration'])]
class Sound extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    public function vibes(): BelongsToMany
    {
        return $this->belongsToMany(Vibe::class, 'vibe_sounds')
            ->withPivot(['volume', 'loop', 'order'])
            ->using(VibeSound::class);
    }
}
