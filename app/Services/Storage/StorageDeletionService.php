<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\Sound;
use App\Models\StorageDeletionTask;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Durable, idempotent removal of Spaces objects.
 *
 * The database and Spaces are not one transaction, so deleting a sound is split in two:
 *  1. {@see self::scheduleForSound()} records *what* must disappear, inside the same transaction that deletes the row;
 *  2. {@see self::runPending()} deletes it, can be repeated safely, and only marks a task done once a fresh
 *     listing/HEAD confirms nothing deletable is left. A crash or Spaces outage leaves tasks pending for the next run.
 *
 * Objects whose public URL is still stored by another row are never deleted (same guard as before).
 */
final class StorageDeletionService
{
    public function __construct(
        private readonly DigitalOceanSpacesService $spaces,
        private readonly StoragePathBuilder $paths,
        private readonly StorageAssetReferenceService $references,
    ) {}

    /**
     * Must run before the sound row is deleted (it reads the revisions that the delete cascades away).
     *
     * @return list<StorageDeletionTask>
     */
    public function scheduleForSound(Sound $sound): array
    {
        $keys = [];

        foreach ([$sound->file_url, $sound->thumbnail_url] as $url) {
            if (is_string($url) && trim($url) !== '') {
                $key = $this->spaces->keyFromUrl(trim($url));
                if ($key !== null) {
                    $keys[$key] = true;
                }
            }
        }

        foreach ($sound->audioRevisions()->get(['source_key', 'distribution_key']) as $revision) {
            foreach ([$revision->source_key, $revision->distribution_key] as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        $tasks = [];
        foreach (array_keys($keys) as $key) {
            $tasks[] = $this->task(StorageDeletionTask::KIND_KEY, $key);
        }

        $tasks[] = $this->task(StorageDeletionTask::KIND_PREFIX, rtrim($this->paths->soundAudioPrefix($sound->id), '/'));
        $tasks[] = $this->task(StorageDeletionTask::KIND_PREFIX, 'sounds/'.$sound->id.'/thumbnail');

        return $tasks;
    }

    /**
     * @return array{done: int, pending: int}
     */
    public function runPending(int $limit = 100): array
    {
        $done = 0;
        $pending = 0;

        $tasks = StorageDeletionTask::query()
            ->where('status', StorageDeletionTask::STATUS_PENDING)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($tasks as $task) {
            $this->run($task) ? $done++ : $pending++;
        }

        return ['done' => $done, 'pending' => $pending];
    }

    public function pendingCount(): int
    {
        return StorageDeletionTask::query()->where('status', StorageDeletionTask::STATUS_PENDING)->count();
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function hasPending(array $taskIds): bool
    {
        return StorageDeletionTask::query()
            ->whereIn('id', $taskIds)
            ->where('status', StorageDeletionTask::STATUS_PENDING)
            ->exists();
    }

    private function task(string $kind, string $target): StorageDeletionTask
    {
        $task = StorageDeletionTask::query()->firstOrCreate(
            ['kind' => $kind, 'target' => $target],
            ['status' => StorageDeletionTask::STATUS_PENDING],
        );

        if ($task->status === StorageDeletionTask::STATUS_DONE) {
            // A new sound can reuse a previously purged id only if ids wrap; re-arm rather than assume.
            $task->forceFill(['status' => StorageDeletionTask::STATUS_PENDING, 'completed_at' => null])->save();
        }

        return $task;
    }

    private function run(StorageDeletionTask $task): bool
    {
        try {
            $complete = $task->kind === StorageDeletionTask::KIND_PREFIX
                ? $this->purgePrefix($task->target)
                : $this->purgeKey($task->target);

            $task->forceFill([
                'attempts' => $task->attempts + 1,
                'status' => $complete ? StorageDeletionTask::STATUS_DONE : StorageDeletionTask::STATUS_PENDING,
                'completed_at' => $complete ? now() : null,
                'last_error' => $complete ? null : 'Objects remain after delete attempt.',
            ])->save();

            return $complete;
        } catch (Throwable $e) {
            Log::warning('storage.deletion_task.failed', [
                'task_id' => $task->id,
                'kind' => $task->kind,
                'target' => $task->target,
                'attempts' => $task->attempts + 1,
                'message' => $e->getMessage(),
            ]);

            $task->forceFill([
                'attempts' => $task->attempts + 1,
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            return false;
        }
    }

    private function purgeKey(string $key): bool
    {
        if ($this->isReferenced($key)) {
            return true;
        }

        $this->spaces->delete($key);

        return ! $this->spaces->exists($key);
    }

    private function purgePrefix(string $prefix): bool
    {
        foreach ($this->spaces->keysUnderPrefix($prefix) as $key) {
            if (! $this->isReferenced($key)) {
                $this->spaces->delete($key);
            }
        }

        foreach ($this->spaces->keysUnderPrefix($prefix) as $key) {
            if (! $this->isReferenced($key)) {
                return false;
            }
        }

        return true;
    }

    private function isReferenced(string $key): bool
    {
        return $this->references->countReferencesToUrl($this->spaces->publicUrl($key)) > 0;
    }
}
