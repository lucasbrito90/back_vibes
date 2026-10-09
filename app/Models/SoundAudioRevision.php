<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audio\AudioRevisionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One upload attempt for a sound's audio. The row is the source of truth for processing state;
 * the queue is only the delivery mechanism.
 *
 * @property int $id
 * @property int $sound_id
 * @property int $version
 * @property AudioRevisionStatus $status
 * @property string $source_key
 * @property string|null $distribution_key
 * @property int $attempts
 */
final class SoundAudioRevision extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AudioRevisionStatus::class,
            'version' => 'integer',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sound, $this>
     */
    public function sound(): BelongsTo
    {
        return $this->belongsTo(Sound::class);
    }
}
