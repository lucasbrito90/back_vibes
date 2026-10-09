<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sound;
use App\Services\Audio\AudioDispatchException;
use App\Services\Audio\AudioRevisionStatus;
use App\Services\Audio\SoundAudioStatus;
use App\Services\Audio\SoundAudioSubmission;
use App\Services\Storage\DigitalOceanSpacesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs sounds whose published audio did not come out of the pipeline (e.g. original WAVs adopted by the
 * backfill) through the same FFmpeg worker, as a new version.
 *
 * It adds no processing logic of its own: it copies the currently published object to a new *private source*
 * and enqueues a normal revision. Consequently:
 *  - the published version keeps serving until the worker has transcoded, validated and published the new one;
 *  - the original object and every previous version are left untouched;
 *  - a sound with a revision already queued/processing is skipped (no concurrent processing), and a sound that is
 *    already optimized is skipped (idempotent re-runs) unless --force is given.
 *
 * Dry run by default: it only reads (database + Spaces HEAD) and reports what --apply would do.
 */
final class AudioReprocessCommand extends Command
{
    protected $signature = 'sounds:audio-reprocess
                            {--sound=* : Reprocess these sound ids}
                            {--all : Every sound whose current audio is not optimized yet}
                            {--limit=0 : Maximum number of sounds to enqueue in one run (0 = no limit)}
                            {--force : Also reprocess sounds that are already optimized (e.g. after a profile change)}
                            {--apply : Enqueue the work (default is a read-only dry run)}';

    protected $description = 'Re-run existing sound audio through the FFmpeg pipeline as a new version (dry run unless --apply).';

    public function handle(DigitalOceanSpacesService $spaces, SoundAudioSubmission $submission): int
    {
        /** @var list<string> $ids */
        $ids = (array) $this->option('sound');
        $all = (bool) $this->option('all');

        if ($ids === [] && ! $all) {
            $this->error('Pass --sound=<id> (repeatable) or --all.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));

        $enqueued = 0;
        $counts = [];
        $rows = [];

        Sound::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', array_map('intval', $ids)))
            ->when($ids === [], fn ($q) => $q->where('audio_optimized', false))
            ->orderBy('id')
            ->chunkById(100, function ($sounds) use ($spaces, $submission, $apply, $force, $limit, &$enqueued, &$counts, &$rows): bool {
                foreach ($sounds as $sound) {
                    if ($limit > 0 && $enqueued >= $limit) {
                        return false;
                    }

                    [$outcome, $key] = $this->evaluate($sound, $spaces, $force);

                    if ($outcome === 'eligible') {
                        $outcome = $apply ? $this->enqueue($sound, (string) $key, $submission) : 'would_enqueue';
                        if (in_array($outcome, ['enqueued', 'would_enqueue'], true)) {
                            $enqueued++;
                        }
                    }

                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                    $rows[] = [$sound->id, $outcome, $key ?? '-'];
                }

                return true;
            });

        $this->table(['sound', 'outcome', 'current object'], $rows);
        $this->line(($apply ? '[apply] ' : '[dry-run] ').json_encode($counts));

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function evaluate(Sound $sound, DigitalOceanSpacesService $spaces, bool $force): array
    {
        $url = trim((string) $sound->file_url);
        if ($sound->audio_status === SoundAudioStatus::Pending || $url === '') {
            return ['skipped_no_audio', null];
        }

        if ($sound->audio_optimized && ! $force) {
            return ['skipped_optimized', null];
        }

        $key = $spaces->keyFromUrl($url);
        if ($key === null) {
            return ['skipped_external', null];
        }

        if ($sound->audioRevisions()->whereIn('status', array_map(
            static fn (AudioRevisionStatus $s): string => $s->value,
            AudioRevisionStatus::inFlight(),
        ))->exists()) {
            return ['skipped_in_flight', $key];
        }

        try {
            if (! $spaces->exists($key) || $spaces->size($key) <= 0) {
                return ['skipped_missing', $key];
            }
        } catch (Throwable) {
            return ['skipped_missing', $key];
        }

        return ['eligible', $key];
    }

    private function enqueue(Sound $sound, string $key, SoundAudioSubmission $submission): string
    {
        try {
            return $submission->submitStoredObject($sound, $key) === null ? 'skipped_in_flight' : 'enqueued';
        } catch (AudioDispatchException) {
            return 'dispatch_failed';
        } catch (Throwable $e) {
            $this->warn("sound {$sound->id}: {$e->getMessage()}");

            return 'error';
        }
    }
}
