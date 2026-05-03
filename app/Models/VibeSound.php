<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class VibeSound extends Pivot
{
    public $timestamps = true;

    protected $table = 'vibe_sounds';

    protected $fillable = [
        'vibe_id',
        'sound_id',
        'volume',
        'loop',
        'sort_order',
        'start_offset_seconds',
        'play_duration_seconds',
        'fade_in_seconds',
        'fade_out_seconds',
    ];

    protected function casts(): array
    {
        return [
            'volume'               => 'integer',
            'loop'                 => 'boolean',
            'sort_order'           => 'integer',
            'start_offset_seconds' => 'integer',
            'play_duration_seconds'=> 'integer',
            'fade_in_seconds'      => 'integer',
            'fade_out_seconds'     => 'integer',
        ];
    }

    public function vibe(): BelongsTo
    {
        return $this->belongsTo(Vibe::class);
    }

    public function sound(): BelongsTo
    {
        return $this->belongsTo(Sound::class);
    }
}
