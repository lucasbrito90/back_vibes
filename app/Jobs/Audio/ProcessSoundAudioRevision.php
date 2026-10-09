<?php

declare(strict_types=1);

namespace App\Jobs\Audio;

use App\Services\Audio\SoundAudioProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Carries only the revision id: the revision row (not the queue) is the source of truth, so a lost,
 * duplicated or re-delivered job can never publish anything on its own.
 */
final class ProcessSoundAudioRevision implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $revisionId)
    {
        $this->tries = (int) config('audio.queue.tries');
        $this->timeout = (int) config('audio.queue.timeout');
        $this->onConnection((string) config('audio.queue.connection'));
        $this->onQueue((string) config('audio.queue.name'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_map('intval', (array) config('audio.queue.backoff'));
    }

    public function handle(SoundAudioProcessor $processor): void
    {
        $processor->process($this->revisionId);
    }

    public function failed(?Throwable $exception): void
    {
        app(SoundAudioProcessor::class)->markFailedIfInFlight(
            $this->revisionId,
            'processing_failed',
            $exception?->getMessage() ?? 'Audio processing failed.',
        );
    }
}
