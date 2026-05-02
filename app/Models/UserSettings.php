<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'default_volume', 'sleep_timer_default', 'preferred_device_id'])]
class UserSettings extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function preferredDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'preferred_device_id');
    }
}
