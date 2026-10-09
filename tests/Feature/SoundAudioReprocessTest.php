<?php

declare(strict_types=1);

use App\Jobs\Audio\ProcessSoundAudioRevision;
use App\Models\Sound;
use App\Models\SoundAudioRevision;
use App\Services\Audio\AudioProcessingException;
use App\Services\Audio\AudioRevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'filesystems.disks.spaces.throw' => true,
        'filesystems.disks.spaces.url' => 'https://cdn.test',
        'filesystems.disks.spaces.bucket' => 'ixora-buckets',
        'filesystems.disks.spaces.region' => 'tor1',
        'filesystems.disks.spaces.endpoint' => 'https://tor1.digitaloceanspaces.com',
    ]);
    Storage::fake('spaces');
});

/** A legacy QA-style sound: public original.wav, versioned by the backfill (or not), never optimized. */
function reprocessLegacySound(string $name = 'QA-A-loop', bool $backfilled = false): Sound
{
    $sound = Sound::query()->create(['name' => $name, 'category' => 'qa', 'file_url' => '', 'duration' => 30]);
    $key = "sounds/{$sound->id}/audio/original.wav";
    Storage::disk('spaces')->put($key, 'original-wav-bytes', ['visibility' => 'public']);
    $sound->forceFill(['file_url' => 'https://cdn.test/'.$key])->save();

    if ($backfilled) {
        test()->artisan('sounds:audio-backfill', ['--apply' => true, '--sound' => [$sound->id]])->assertSuccessful();
    }

    return $sound->refresh();
}

test('without --sound or --all it refuses to run', function (): void {
    test()->artisan('sounds:audio-reprocess')->assertFailed();
});

test('dry run reports eligibility and writes nothing, not even to Spaces', function (): void {
    Queue::fake();
    $sound = reprocessLegacySound();
    $before = Storage::disk('spaces')->allFiles();

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower(ltrim($query->sql));
    });

    test()->artisan('sounds:audio-reprocess', ['--all' => true])->assertSuccessful();

    expect(array_values(array_filter($statements, fn (string $sql): bool => ! str_starts_with($sql, 'select'))))->toBe([])
        ->and(Storage::disk('spaces')->allFiles())->toBe($before)
        ->and(SoundAudioRevision::query()->count())->toBe(0)
        ->and($sound->refresh()->audio_last_reserved_version)->toBe(0);
    Queue::assertNothingPushed();
});

test('apply enqueues a new version through the normal job and leaves the original untouched', function (): void {
    Queue::fake();
    $sound = reprocessLegacySound();
    $originalUrl = $sound->file_url;

    test()->artisan('sounds:audio-reprocess', ['--sound' => [$sound->id], '--apply' => true])->assertSuccessful();

    $revision = SoundAudioRevision::query()->firstOrFail();
    expect($revision->origin)->toBe('reprocess')
        ->and($revision->version)->toBe(1)
        ->and($revision->status)->toBe(AudioRevisionStatus::Queued)
        ->and($revision->source_key)->toBe("sounds/{$sound->id}/audio/sources/v1/source.wav")
        ->and($sound->refresh()->file_url)->toBe($originalUrl)
        ->and($sound->audio_optimized)->toBeFalse();

    expect(Storage::disk('spaces')->get($revision->source_key))->toBe('original-wav-bytes');
    Storage::disk('spaces')->assertExists("sounds/{$sound->id}/audio/original.wav");
    Queue::assertPushed(ProcessSoundAudioRevision::class, fn ($job) => $job->revisionId === $revision->id && $job->queue === 'audio');
});

test('the copied source is private even though the original is public', function (): void {
    Queue::fake();
    $sound = reprocessLegacySound();

    $real = Storage::disk('spaces');
    $writes = [];
    $spy = Mockery::mock($real);
    $spy->shouldReceive('put')->andReturnUsing(function (string $path, $contents, $options = []) use ($real, &$writes) {
        $writes[$path] = $options;

        return $real->put($path, $contents, $options);
    });
    Storage::set('spaces', $spy);

    test()->artisan('sounds:audio-reprocess', ['--sound' => [$sound->id], '--apply' => true])->assertSuccessful();

    expect($writes["sounds/{$sound->id}/audio/sources/v1/source.wav"]['visibility'])->toBe('private');
});

test('end to end: the old URL serves until the worker publishes, then the sound becomes optimized', function (): void {
    $sound = reprocessLegacySound(backfilled: true);
    $legacyUrl = $sound->file_url;
    expect($sound->audio_version)->toBe(1)->and($sound->audio_optimized)->toBeFalse();

    // The queue is sync in tests: observe the sound while the (fake) transcoder is working.
    $seenDuringWork = null;
    test()->audioTranscoder->onTranscode = function () use ($sound, &$seenDuringWork): void {
        $fresh = $sound->fresh();
        $seenDuringWork = [$fresh->file_url, $fresh->audio_version, $fresh->audio_optimized];
    };

    test()->artisan('sounds:audio-reprocess', ['--sound' => [$sound->id], '--apply' => true])->assertSuccessful();

    $sound->refresh();
    expect($seenDuringWork)->toBe([$legacyUrl, 1, false])
        ->and($sound->audio_version)->toBe(2)
        ->and($sound->audio_optimized)->toBeTrue()
        ->and($sound->file_url)->toBe("https://cdn.test/sounds/{$sound->id}/audio/v2/distribution.m4a");

    // Nothing that existed before was deleted.
    Storage::disk('spaces')->assertExists("sounds/{$sound->id}/audio/original.wav");
    Storage::disk('spaces')->assertExists("sounds/{$sound->id}/audio/sources/v2/source.wav");
    Storage::disk('spaces')->assertExists("sounds/{$sound->id}/audio/v2/distribution.m4a");

    $published = SoundAudioRevision::query()->where('version', 2)->firstOrFail();
    expect($published->profile)->toBe(config('audio.profile.name'))
        ->and(SoundAudioRevision::query()->where('version', 1)->value('origin'))->toBe('backfill');
});

test('a failing conversion keeps the published original working and is never marked optimized', function (): void {
    $sound = reprocessLegacySound(backfilled: true);
    $legacyUrl = $sound->file_url;
    test()->audioTranscoder->probeSourceError = AudioProcessingException::permanent('unreadable_media', 'not decodable');

    test()->artisan('sounds:audio-reprocess', ['--sound' => [$sound->id], '--apply' => true])->assertSuccessful();

    $sound->refresh();
    expect($sound->file_url)->toBe($legacyUrl)
        ->and($sound->audio_version)->toBe(1)
        ->and($sound->audio_optimized)->toBeFalse()
        ->and(SoundAudioRevision::query()->where('version', 2)->first()->status)->toBe(AudioRevisionStatus::Failed);
    Storage::disk('spaces')->assertExists("sounds/{$sound->id}/audio/original.wav");
});

test('re-running is idempotent: optimized sounds and sounds in flight are skipped', function (): void {
    $sound = reprocessLegacySound();

    test()->artisan('sounds:audio-reprocess', ['--all' => true, '--apply' => true])->assertSuccessful();
    expect($sound->refresh()->audio_optimized)->toBeTrue();
    $revisions = SoundAudioRevision::query()->count();

    // Second run: --all no longer selects it; explicit id reports skipped_optimized and creates nothing.
    test()->artisan('sounds:audio-reprocess', ['--all' => true, '--apply' => true])->assertSuccessful();
    test()->artisan('sounds:audio-reprocess', ['--sound' => [$sound->id], '--apply' => true])->assertSuccessful();
    expect(SoundAudioRevision::query()->count())->toBe($revisions);

    // --force re-runs an optimized sound (e.g. after a profile change) as a new version.
    test()->artisan('sounds:audio-reprocess', ['--sound' => [$sound->id], '--apply' => true, '--force' => true])->assertSuccessful();
    expect(SoundAudioRevision::query()->count())->toBe($revisions + 1)
        ->and($sound->refresh()->audio_version)->toBe(2);
});

test('a sound that already has a revision in flight is not processed concurrently', function (): void {
    Queue::fake();
    $sound = reprocessLegacySound();

    test()->artisan('sounds:audio-reprocess', ['--sound' => [$sound->id], '--apply' => true])->assertSuccessful();
    test()->artisan('sounds:audio-reprocess', ['--sound' => [$sound->id], '--apply' => true])->assertSuccessful();

    expect(SoundAudioRevision::query()->count())->toBe(1)
        ->and($sound->refresh()->audio_last_reserved_version)->toBe(1);
    Queue::assertPushed(ProcessSoundAudioRevision::class, 1);
});

test('skips sounds without audio, external URLs and missing objects, and honours --limit', function (): void {
    Queue::fake();
    Sound::query()->create(['name' => 'pending', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 1]);
    Sound::query()->create(['name' => 'external', 'category' => 'c', 'file_url' => 'https://elsewhere.example/a.wav', 'duration' => 1]);
    Sound::query()->create(['name' => 'missing', 'category' => 'c', 'file_url' => 'https://cdn.test/sounds/99/audio/original.wav', 'duration' => 1]);
    $a = reprocessLegacySound('a');
    $b = reprocessLegacySound('b');

    test()->artisan('sounds:audio-reprocess', ['--all' => true, '--apply' => true, '--limit' => 1])->assertSuccessful();

    expect(SoundAudioRevision::query()->count())->toBe(1)
        ->and(SoundAudioRevision::query()->first()->sound_id)->toBe($a->id)
        ->and(SoundAudioRevision::query()->where('sound_id', $b->id)->exists())->toBeFalse();
});

test('when the job cannot be enqueued the sound is untouched and no private copy is left behind', function (): void {
    $sound = reprocessLegacySound();
    config(['audio.queue.connection' => 'connection-that-does-not-exist']);

    test()->artisan('sounds:audio-reprocess', ['--sound' => [$sound->id], '--apply' => true])->assertSuccessful();

    expect($sound->refresh()->file_url)->toContain('original.wav')
        ->and(SoundAudioRevision::query()->first()->error_code)->toBe('dispatch_failed');
    Storage::disk('spaces')->assertMissing("sounds/{$sound->id}/audio/sources/v1/source.wav");
    Storage::disk('spaces')->assertExists("sounds/{$sound->id}/audio/original.wav");
});

test('the backfill never marks adopted legacy files as optimized', function (): void {
    $sound = reprocessLegacySound(backfilled: true);

    expect($sound->audio_status->value)->toBe('ready')
        ->and($sound->audio_optimized)->toBeFalse();
});

test('the copy happens inside the transaction that holds the sound lock', function (): void {
    Queue::fake();
    $sound = reprocessLegacySound();
    $outside = DB::transactionLevel();

    $real = Storage::disk('spaces');
    $levels = [];
    $spy = Mockery::mock($real);
    $spy->shouldReceive('put')->andReturnUsing(function (string $path, $contents, $options = []) use ($real, &$levels) {
        $levels[$path] = DB::transactionLevel();

        return $real->put($path, $contents, $options);
    });
    Storage::set('spaces', $spy);

    test()->artisan('sounds:audio-reprocess', ['--sound' => [$sound->id], '--apply' => true])->assertSuccessful();

    // Reservation, copy and revision insert share one locked transaction (race proven on PostgreSQL, see report).
    expect($levels["sounds/{$sound->id}/audio/sources/v1/source.wav"])->toBeGreaterThan($outside);
});

test('a failed copy rolls back the version reservation and leaves no partial source', function (): void {
    Queue::fake();
    $sound = reprocessLegacySound();

    $real = Storage::disk('spaces');
    $spy = Mockery::mock($real);
    $spy->shouldReceive('put')->andReturnUsing(function (string $path, $contents, $options = []) use ($real) {
        if (str_contains($path, '/sources/')) {
            $real->put($path, 'partial', $options);
            throw new RuntimeException('spaces unavailable');
        }

        return $real->put($path, $contents, $options);
    });
    Storage::set('spaces', $spy);

    test()->artisan('sounds:audio-reprocess', ['--sound' => [$sound->id], '--apply' => true])->assertSuccessful();

    expect($sound->refresh()->audio_last_reserved_version)->toBe(0)
        ->and(SoundAudioRevision::query()->count())->toBe(0);
    Storage::disk('spaces')->assertMissing("sounds/{$sound->id}/audio/sources/v1/source.wav");
    Storage::disk('spaces')->assertExists("sounds/{$sound->id}/audio/original.wav");
    Queue::assertNothingPushed();
});
