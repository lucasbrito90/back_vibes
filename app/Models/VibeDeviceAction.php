<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vibe_id', 'device_id', 'action_type', 'parameters', 'delay_seconds'])]
class VibeDeviceAction extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
        ];
    }

    public function vibe(): BelongsTo
    {
        return $this->belongsTo(Vibe::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
