<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Jobs\Audio\ProcessSoundAudioRevision;
use App\Models\SoundAudioRevision;

/**
 * The only place that hands a revision to the queue. A dispatch failure is surfaced to the caller:
 * it must never fall back to synchronous conversion inside the HTTP request.
 */
final class SoundAudioRevisionDispatcher
{
    /**
     * @throws AudioDispatchException
     */
    public function dispatch(SoundAudioRevision $revision): void
    {
        try {
            ProcessSoundAudioRevision::dispatch($revision->id);
        } catch (\Throwable $e) {
            throw new AudioDispatchException('Audio processing could not be enqueued: '.$e->getMessage(), 0, $e);
        }

        SoundAudioRevision::query()->whereKey($revision->id)->update(['dispatched_at' => now()]);
    }
}
