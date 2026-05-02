<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class VibeSound extends Pivot
{
    public $timestamps = false;

    protected $table = 'vibe_sounds';

    protected $fillable = ['vibe_id', 'sound_id', 'volume', 'loop', 'sort_order'];

    protected function casts(): array
    {
        return [
            'volume'     => 'integer',
            'loop'       => 'boolean',
            'sort_order' => 'integer',
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
