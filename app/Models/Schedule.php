<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'vibe_id',
    'name',
    'timezone',
    'start_time',
    'recurrence_type',
    'recurrence_config',
    'is_enabled',
    'next_run_at',
    'last_run_at',
])]
final class Schedule extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'recurrence_config' => 'array',
            'is_enabled' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
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
