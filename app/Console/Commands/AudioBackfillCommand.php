<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sound;
use App\Models\SoundAudioRevision;
use App\Services\Audio\AudioRevisionStatus;
use App\Services\Audio\SoundAudioStatus;
use App\Services\Storage\DigitalOceanSpacesService;
use App\Services\Storage\StoragePathBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Inventories the pre-versioning catalog against the real Spaces objects and, only with --apply, gives each
 * *verified* sound a published version. Nothing is ever marked ready from the database alone, and nothing is
 * ever marked optimized: converting existing files is the job of `sounds:audio-reprocess`.
 *
 * Outcome per sound:
 *   verified_canonical  object exists at sounds/{id}/audio/original.<ext>
 *   verified_legacy     object exists at another Spaces path
 *   missing             URL points at a Spaces key that does not exist (stays `legacy`, never ready)
 *   empty_object        object exists but has 0 bytes (stays `legacy`)
 *   external            URL is not a Spaces/CDN URL, cannot be verified here (stays `legacy`)
 *   no_audio            row has no file_url and no published version (stays/becomes `pending`)
 *   already_versioned   nothing to do
 */
final class AudioBackfillCommand extends Command
{
    protected $signature = 'sounds:audio-backfill
                            {--apply : Persist versions for verified sounds (default is a read-only dry run)}
                            {--sound=* : Limit to these sound ids}';

    protected $description = 'Verify legacy sound audio objects in Spaces and assign them a published version (dry run unless --apply).';

    public function handle(DigitalOceanSpacesService $spaces, StoragePathBuilder $paths): int
    {
        $apply = (bool) $this->option('apply');
        /** @var list<string> $only */
        $only = (array) $this->option('sound');

        $counts = [];
        $rows = [];

        Sound::query()
            ->when($only !== [], fn ($q) => $q->whereIn('id', array_map('intval', $only)))
            ->orderBy('id')
            ->chunkById(100, function ($sounds) use ($spaces, $paths, $apply, &$counts, &$rows): void {
                foreach ($sounds as $sound) {
                    [$outcome, $key, $bytes] = $this->inspect($sound, $spaces, $paths);
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;

                    $applied = false;
                    if ($apply && in_array($outcome, ['verified_canonical', 'verified_legacy'], true) && $key !== null) {
                        $applied = $this->publishLegacy($sound, $key, (int) $bytes);
                    }

                    $rows[] = [$sound->id, $outcome, $key ?? '-', $bytes ?? '-', $applied ? 'applied' : ($apply ? 'skipped' : 'dry-run')];
                }
            });

        $this->table(['sound', 'outcome', 'key', 'bytes', 'action'], $rows);
        $this->line(($apply ? '[apply] ' : '[dry-run] ').json_encode($counts));

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string|null, 2: int|null}
     */
    private function inspect(Sound $sound, DigitalOceanSpacesService $spaces, StoragePathBuilder $paths): array
    {
        if ($sound->audio_version !== null) {
            return ['already_versioned', null, null];
        }

        $url = trim((string) $sound->file_url);
        if ($url === '') {
            return ['no_audio', null, null];
        }

        $key = $spaces->keyFromUrl($url);
        if ($key === null) {
            return ['external', null, null];
        }

        try {
            if (! $spaces->exists($key)) {
                return ['missing', $key, null];
            }

            $bytes = $spaces->size($key);
        } catch (Throwable) {
            return ['missing', $key, null];
        }

        if ($bytes <= 0) {
            return ['empty_object', $key, 0];
        }

        $canonicalPrefix = rtrim($paths->soundAudioPrefix($sound->id), '/').'/original.';

        return [str_starts_with($key, $canonicalPrefix) ? 'verified_canonical' : 'verified_legacy', $key, $bytes];
    }

    /**
     * The legacy object is both source and distribution of version N. Safe to treat as immutable because every
     * route that wrote to these keys (admin/uploads for sound audio, PATCH file_url) is closed.
     */
    private function publishLegacy(Sound $sound, string $key, int $bytes): bool
    {
        return DB::transaction(function () use ($sound, $key, $bytes): bool {
            /** @var Sound|null $locked */
            $locked = Sound::query()->whereKey($sound->id)->lockForUpdate()->first();
            if ($locked === null || $locked->audio_version !== null || trim((string) $locked->file_url) !== trim((string) $sound->file_url)) {
                return false;
            }

            $version = max(1, ((int) $locked->audio_last_reserved_version) + 1);

            SoundAudioRevision::query()->create([
                'sound_id' => $locked->id,
                'version' => $version,
                'status' => AudioRevisionStatus::Ready->value,
                'origin' => 'backfill',
                'source_key' => $key,
                'distribution_key' => $key,
                'distribution_bytes' => $bytes,
                'attempts' => 0,
                'queued_at' => now(),
                'finished_at' => now(),
            ]);

            $locked->forceFill([
                'audio_version' => $version,
                'audio_last_reserved_version' => $version,
                'audio_status' => SoundAudioStatus::Ready->value,
                // Adopted as-is (often an original WAV): versioned and verified, but NOT optimized.
                'audio_optimized' => false,
            ])->save();

            return true;
        });
    }
}
