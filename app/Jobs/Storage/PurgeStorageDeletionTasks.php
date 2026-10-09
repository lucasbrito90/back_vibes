<?php

declare(strict_types=1);

namespace App\Jobs\Storage;

use App\Services\Storage\StorageDeletionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Retries pending storage deletions. Safe to run any number of times: the tasks themselves are the state.
 */
final class PurgeStorageDeletionTasks implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(StorageDeletionService $deletion): void
    {
        $deletion->runPending();

        if ($deletion->pendingCount() > 0) {
            $this->release(300);
        }
    }
}
