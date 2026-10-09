<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Models\SoundAudioRevision;
use App\Services\Storage\StorageDeletionService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for everything the queue cannot guarantee: jobs lost between commit and dispatch, workers
 * killed mid-job, and storage deletions that failed. Every action is idempotent (the processor claims
 * revisions atomically), so running it concurrently or repeatedly is harmless.
 */
final class SoundAudioRecovery
{
    public function __construct(
        private readonly SoundAudioRevisionDispatcher $dispatcher,
        private readonly SoundAudioProcessor $processor,
        private readonly StorageDeletionService $deletion,
    ) {}

    /**
     * @return array{requeued: int, failed: int, deletions_done: int, deletions_pending: int, temp_dirs_removed: int}
     */
    public function run(): array
    {
        $requeued = 0;
        $failed = 0;
        $maxAttempts = (int) config('audio.recovery.max_attempts');

        $staleBefore = now()->subSeconds((int) config('audio.recovery.stale_after_seconds'));
        $undispatchedBefore = now()->subSeconds((int) config('audio.recovery.undispatched_after_seconds'));
        $lostBefore = now()->subSeconds((int) config('audio.recovery.queued_redispatch_after_seconds'));

        $candidates = SoundAudioRevision::query()
            ->where(function ($query) use ($staleBefore, $undispatchedBefore, $lostBefore): void {
                $query->where(function ($stale) use ($staleBefore): void {
                    $stale->where('status', AudioRevisionStatus::Processing->value)
                        ->where('heartbeat_at', '<', $staleBefore);
                })->orWhere(function ($undispatched) use ($undispatchedBefore): void {
                    $undispatched->where('status', AudioRevisionStatus::Queued->value)
                        ->whereNull('dispatched_at')
                        ->where('queued_at', '<', $undispatchedBefore);
                })->orWhere(function ($lost) use ($lostBefore): void {
                    $lost->where('status', AudioRevisionStatus::Queued->value)
                        ->whereNotNull('dispatched_at')
                        ->where('dispatched_at', '<', $lostBefore);
                });
            })
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($candidates as $revision) {
            if ($revision->attempts >= $maxAttempts) {
                $this->processor->markFailedIfInFlight($revision->id, 'max_attempts_exceeded', 'Audio processing exceeded the maximum number of attempts.');
                $failed++;

                continue;
            }

            if ($revision->status === AudioRevisionStatus::Processing) {
                SoundAudioRevision::query()
                    ->whereKey($revision->id)
                    ->where('status', AudioRevisionStatus::Processing->value)
                    ->update(['status' => AudioRevisionStatus::Queued->value]);
            }

            try {
                $this->dispatcher->dispatch($revision);
                $requeued++;
            } catch (AudioDispatchException $e) {
                Log::error('audio.recovery.dispatch_failed', ['revision_id' => $revision->id, 'message' => $e->getMessage()]);
            }
        }

        $deletions = $this->deletion->runPending();

        return [
            'requeued' => $requeued,
            'failed' => $failed,
            'deletions_done' => $deletions['done'],
            'deletions_pending' => $deletions['pending'],
            'temp_dirs_removed' => $this->sweepWorkDirectories(),
        ];
    }

    /**
     * A worker killed by the queue timeout (SIGKILL) never runs its `finally`, so its temporary download/encode
     * directory stays on disk. A directory untouched for longer than any job can run is safe to remove.
     */
    private function sweepWorkDirectories(): int
    {
        $base = rtrim((string) (config('audio.ffmpeg.temp_directory') ?: sys_get_temp_dir()), '/'.chr(92));
        $olderThan = now()->subSeconds((int) config('audio.recovery.stale_after_seconds') * 2)->getTimestamp();
        $removed = 0;

        foreach (glob($base.DIRECTORY_SEPARATOR.'ixora-audio-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < $olderThan && File::deleteDirectory($dir)) {
                $removed++;
            }
        }

        return $removed;
    }
}
