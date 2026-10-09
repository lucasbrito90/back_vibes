<?php

namespace App\Models;

use App\Services\Audio\SoundAudioStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name',
    'file_url',
    'audio_version',
    'audio_last_reserved_version',
    'audio_status',
    'audio_optimized',
    'thumbnail_url',
    'category',
    'duration',
    'tags',
    'is_active',
])]
final class Sound extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_active' => 'boolean',
            'audio_version' => 'integer',
            'audio_last_reserved_version' => 'integer',
            'audio_optimized' => 'boolean',
            'audio_status' => SoundAudioStatus::class,
        ];
    }

    public function vibes(): BelongsToMany
    {
        return $this->belongsToMany(Vibe::class, 'vibe_sounds')
            ->withPivot(['volume', 'loop', 'sort_order'])
            ->using(VibeSound::class);
    }

    /**
     * @return HasMany<SoundAudioRevision, $this>
     */
    public function audioRevisions(): HasMany
    {
        return $this->hasMany(SoundAudioRevision::class);
    }

    /**
     * @return HasOne<SoundAudioRevision, $this>
     */
    public function latestAudioRevision(): HasOne
    {
        return $this->hasOne(SoundAudioRevision::class)->latestOfMany('version');
    }

    /** True when `file_url` points at audio a client can actually play. */
    public function hasPlayableAudio(): bool
    {
        return $this->audio_status !== SoundAudioStatus::Pending
            && is_string($this->file_url)
            && trim($this->file_url) !== '';
    }
}
