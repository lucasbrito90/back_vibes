<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'vibe_id', 'name', 'start_time', 'recurrence_type', 'recurrence_config', 'is_enabled'])]
class Schedule extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'start_time'        => 'datetime',
            'recurrence_config' => 'array',
            'is_enabled'        => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vibe(): BelongsTo
    {
        return $this->belongsTo(Vibe::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ScheduleExecution::class);
    }
}
