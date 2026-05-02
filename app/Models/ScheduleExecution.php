<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['schedule_id', 'executed_at', 'status', 'log'])]
class ScheduleExecution extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
