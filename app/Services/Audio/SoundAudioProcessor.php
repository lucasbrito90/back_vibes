<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Models\Sound;
use App\Models\SoundAudioRevision;
use App\Services\Storage\DigitalOceanSpacesService;
use App\Services\Storage\StoragePathBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns an uploaded (private) source into a published, versioned distribution asset.
 *
 * Invariants:
 *  - the sound only ever points at an object that was transcoded, validated, uploaded and size-verified;
 *  - the publish step runs under a row lock on the sound and only moves `audio_version` forward;
 *  - any failure leaves the previously published version untouched.
 */
final class SoundAudioProcessor
{
    private const IMMUTABLE_CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __construct(
        private readonly AudioTranscoding $transcoder,
        private readonly DigitalOceanSpacesService $spaces,
        private readonly StoragePathBuilder $paths,
    ) {}

    /**
     * Idempotent: running it again for a terminal or already-claimed revision is a no-op.
     *
     * @throws Throwable retryable failures, after the revision was put back to `queued`
     */
    public function process(int $revisionId): void
    {
        $revision = SoundAudioRevision::query()->find($revisionId);
        if ($revision === null || $revision->status->isTerminal()) {
            return;
        }

        if (! $this->claim($revision)) {
            Log::info('audio.revision.claim_skipped', ['revision_id' => $revisionId]);

            return;
        }

        $revision->refresh();
        $workDir = null;

        try {
            $workDir = $this->makeWorkDirectory($revision);
            $this->run($revision, $workDir);
        } catch (AudioProcessingException $e) {
            if ($e->retryable) {
                $this->requeue($revision, $e->errorCode, $e->getMessage());

                throw $e;
            }

            $this->markFailed($revision->id, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            $this->requeue($revision, 'unexpected_error', $e->getMessage());

            throw $e;
        } finally {
            if ($workDir !== null) {
                File::deleteDirectory($workDir);
            }
        }
    }

    /** Final failure hook (queue exhausted its tries or the job timed out). */
    public function markFailedIfInFlight(int $revisionId, string $errorCode, string $message): void
    {
        // A retryable failure already recorded the specific cause (e.g. transcode_failed); keep it rather than
        // replacing it with the generic "queue gave up" code.
        $previous = SoundAudioRevision::query()->whereKey($revisionId)->first(['error_code', 'error_message']);
        if ($previous !== null && $previous->error_code !== null) {
            $errorCode = $previous->error_code;
            $message = (string) ($previous->error_message ?: $message);
        }

        $this->markFailed($revisionId, $errorCode, $message);
    }

    private function run(SoundAudioRevision $revision, string $workDir): void
    {
        $sound = Sound::query()->find($revision->sound_id);
        if ($sound === null) {
            return;
        }

        if ((int) $sound->audio_version >= $revision->version) {
            $this->markSuperseded($revision->id);

            return;
        }

        $sourceExtension = pathinfo($revision->source_key, PATHINFO_EXTENSION) ?: 'bin';
        $sourcePath = $workDir.DIRECTORY_SEPARATOR.'source.'.$sourceExtension;
        $profileExtension = (string) config('audio.profile.extension');
        $outputPath = $workDir.DIRECTORY_SEPARATOR.'distribution.'.$profileExtension;

        $this->downloadSource($revision, $sourcePath);
        $this->heartbeat($revision->id);

        $sourceProbe = $this->transcoder->probe($sourcePath);
        $this->assertValidSource($sourceProbe);

        $this->transcoder->transcode($sourcePath, $outputPath);
        $this->heartbeat($revision->id);

        $outputProbe = $this->transcoder->probe($outputPath);
        $outputBytes = $this->assertValidOutput($outputProbe, $sourceProbe, $outputPath);

        $distributionKey = $this->paths->soundAudioDistribution($revision->sound_id, $revision->version, $profileExtension);
        $this->uploadDistribution($distributionKey, $outputPath, $outputBytes);
        $this->heartbeat($revision->id);

        $published = $this->publish($revision, $distributionKey, $outputBytes, (int) $outputProbe->durationMs);

        if (! $published) {
            $this->discardUnpublishedDistribution($revision->id, $distributionKey);
        }
    }

    /**
     * Atomically move queued (or stale-processing) to processing. Only one worker can win.
     */
    private function claim(SoundAudioRevision $revision): bool
    {
        $now = now();
        $staleBefore = $now->copy()->subSeconds((int) config('audio.recovery.stale_after_seconds'));

        $affected = SoundAudioRevision::query()
            ->whereKey($revision->id)
            ->where(function ($query) use ($staleBefore): void {
                $query->where('status', AudioRevisionStatus::Queued->value)
                    ->orWhere(function ($stale) use ($staleBefore): void {
                        $stale->where('status', AudioRevisionStatus::Processing->value)
                            ->where('heartbeat_at', '<', $staleBefore);
                    });
            })
            ->update([
                'status' => AudioRevisionStatus::Processing->value,
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => $now,
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);

        return $affected === 1;
    }

    private function downloadSource(SoundAudioRevision $revision, string $targetPath): void
    {
        try {
            $this->spaces->downloadTo($revision->source_key, $targetPath);
        } catch (Throwable $e) {
            if (! $this->spaces->exists($revision->source_key)) {
                throw AudioProcessingException::permanent('source_missing', 'The uploaded source object no longer exists.', $e);
            }

            throw AudioProcessingException::transient('source_download_failed', 'Unable to download source: '.$e->getMessage(), $e);
        }
    }

    private function assertValidSource(AudioProbe $probe): void
    {
        if ($probe->audioStreams < 1) {
            throw AudioProcessingException::permanent('no_audio_stream', 'The uploaded file has no audio stream.');
        }

        $min = (int) config('audio.validation.min_duration_ms');
        $max = (int) config('audio.validation.max_duration_seconds') * 1000;

        if ($probe->durationMs === null || $probe->durationMs < $min) {
            throw AudioProcessingException::permanent('invalid_duration', 'The uploaded audio is empty or too short.');
        }

        if ($probe->durationMs > $max) {
            throw AudioProcessingException::permanent('duration_exceeds_limit', 'The uploaded audio is longer than the allowed maximum.');
        }
    }

    /**
     * @return int Size of the verified output in bytes
     */
    private function assertValidOutput(AudioProbe $output, AudioProbe $source, string $outputPath): int
    {
        $bytes = is_file($outputPath) ? (int) filesize($outputPath) : 0;
        if ($bytes <= 0) {
            throw AudioProcessingException::permanent('empty_output', 'Transcoding produced an empty file.');
        }

        $expectedCodec = (string) config('audio.profile.codec');
        if ($output->audioStreams !== 1 || $output->otherStreams !== 0 || $output->codec !== $expectedCodec) {
            throw AudioProcessingException::permanent('invalid_output', 'Transcoded file does not match the distribution profile.');
        }

        $sourceMs = (int) $source->durationMs;
        $tolerance = max(
            (int) config('audio.validation.duration_tolerance_ms'),
            (int) round($sourceMs * (float) config('audio.validation.duration_tolerance_ratio')),
        );

        if ($output->durationMs === null || abs($output->durationMs - $sourceMs) > $tolerance) {
            throw AudioProcessingException::permanent('duration_mismatch', 'Transcoded duration differs from the source beyond the allowed tolerance.');
        }

        return $bytes;
    }

    /**
     * The key is unique per reserved version, so an object left by an earlier failed attempt of the
     * same revision (never published) may be overwritten. Published keys are never written here.
     */
    private function uploadDistribution(string $key, string $path, int $expectedBytes): void
    {
        try {
            $this->spaces->putFile($key, $path, 'public', ['CacheControl' => self::IMMUTABLE_CACHE_CONTROL]);

            if ($this->spaces->size($key) !== $expectedBytes) {
                throw new \RuntimeException('Uploaded object size does not match the local file.');
            }
        } catch (Throwable $e) {
            throw AudioProcessingException::transient('upload_failed', 'Unable to publish distribution object: '.$e->getMessage(), $e);
        }
    }

    /**
     * Publish under a row lock. Returns false when the revision lost (a higher version is already current,
     * the sound is gone, or another worker already finalised this revision).
     */
    private function publish(SoundAudioRevision $revision, string $distributionKey, int $bytes, int $durationMs): bool
    {
        return DB::transaction(function () use ($revision, $distributionKey, $bytes, $durationMs): bool {
            $sound = Sound::query()->whereKey($revision->sound_id)->lockForUpdate()->first();
            if ($sound === null) {
                return false;
            }

            if ($revision->version <= (int) $sound->audio_version) {
                $this->markSuperseded($revision->id);

                return false;
            }

            $updated = SoundAudioRevision::query()
                ->whereKey($revision->id)
                ->where('status', AudioRevisionStatus::Processing->value)
                ->update([
                    'status' => AudioRevisionStatus::Ready->value,
                    'distribution_key' => $distributionKey,
                    'distribution_bytes' => $bytes,
                    'profile' => (string) config('audio.profile.name'),
                    'duration_ms' => $durationMs,
                    'error_code' => null,
                    'error_message' => null,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            $sound->forceFill([
                'file_url' => $this->spaces->publicUrl($distributionKey),
                'audio_version' => $revision->version,
                'audio_status' => SoundAudioStatus::Ready->value,
                'audio_optimized' => true,
            ])->save();

            Log::info('audio.revision.published', [
                'sound_id' => $sound->id,
                'revision_id' => $revision->id,
                'version' => $revision->version,
                'bytes' => $bytes,
            ]);

            return true;
        });
    }

    /**
     * Remove a distribution object only when its revision definitively lost; never when it was published
     * (a duplicate worker that lost the race must not delete the winner's object).
     */
    private function discardUnpublishedDistribution(int $revisionId, string $distributionKey): void
    {
        $revision = SoundAudioRevision::query()->find($revisionId);

        if ($revision !== null && $revision->status === AudioRevisionStatus::Ready) {
            return;
        }

        $this->spaces->delete($distributionKey);
    }

    private function markSuperseded(int $revisionId): void
    {
        SoundAudioRevision::query()
            ->whereKey($revisionId)
            ->whereIn('status', $this->inFlightValues())
            ->update([
                'status' => AudioRevisionStatus::Superseded->value,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        Log::info('audio.revision.superseded', ['revision_id' => $revisionId]);
    }

    private function requeue(SoundAudioRevision $revision, string $errorCode, string $message): void
    {
        SoundAudioRevision::query()
            ->whereKey($revision->id)
            ->where('status', AudioRevisionStatus::Processing->value)
            ->update([
                'status' => AudioRevisionStatus::Queued->value,
                'error_code' => $errorCode,
                'error_message' => mb_substr($message, 0, 2000),
                'updated_at' => now(),
            ]);

        Log::warning('audio.revision.retryable_failure', [
            'revision_id' => $revision->id,
            'attempt' => $revision->attempts,
            'error_code' => $errorCode,
            'message' => $message,
        ]);
    }

    private function markFailed(int $revisionId, string $errorCode, string $message): void
    {
        $updated = SoundAudioRevision::query()
            ->whereKey($revisionId)
            ->whereIn('status', $this->inFlightValues())
            ->update([
                'status' => AudioRevisionStatus::Failed->value,
                'error_code' => $errorCode,
                'error_message' => mb_substr($message, 0, 2000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 1) {
            Log::error('audio.revision.failed', [
                'revision_id' => $revisionId,
                'error_code' => $errorCode,
                'message' => $message,
            ]);
        }
    }

    private function heartbeat(int $revisionId): void
    {
        SoundAudioRevision::query()
            ->whereKey($revisionId)
            ->where('status', AudioRevisionStatus::Processing->value)
            ->update(['heartbeat_at' => now()]);
    }

    /** @return list<string> */
    private function inFlightValues(): array
    {
        return array_map(static fn (AudioRevisionStatus $s): string => $s->value, AudioRevisionStatus::inFlight());
    }

    private function makeWorkDirectory(SoundAudioRevision $revision): string
    {
        $base = (string) (config('audio.ffmpeg.temp_directory') ?: sys_get_temp_dir());
        $dir = rtrim($base, '/\\').DIRECTORY_SEPARATOR.'ixora-audio-'.$revision->id.'-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
