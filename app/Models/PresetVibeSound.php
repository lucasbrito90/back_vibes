<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PresetVibeSound extends Model
{
    protected $fillable = [
        'preset_vibe_id',
        'sound_id',
        'volume',
        'loop',
        'play_mode',
        'sort_order',
        'repeat_interval_seconds',
        'start_offset_seconds',
        'play_duration_seconds',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'volume' => 'integer',
            'loop' => 'boolean',
            'sort_order' => 'integer',
            'repeat_interval_seconds' => 'integer',
            'start_offset_seconds' => 'integer',
            'play_duration_seconds' => 'integer',
        ];
    }

    public function presetVibe(): BelongsTo
    {
        return $this->belongsTo(PresetVibe::class);
    }

    public function sound(): BelongsTo
    {
        return $this->belongsTo(Sound::class);
    }
}
