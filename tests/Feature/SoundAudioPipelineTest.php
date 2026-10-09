<?php

declare(strict_types=1);

use App\Jobs\Audio\ProcessSoundAudioRevision;
use App\Jobs\Storage\PurgeStorageDeletionTasks;
use App\Models\Sound;
use App\Models\SoundAudioRevision;
use App\Models\StorageDeletionTask;
use App\Models\User;
use App\Models\Vibe;
use App\Services\Audio\AudioProcessingException;
use App\Services\Audio\AudioRevisionStatus;
use App\Services\Audio\SoundAudioProcessor;
use App\Services\Audio\SoundAudioRecovery;
use App\Services\Storage\StorageDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

const AUDIO_CDN = 'https://cdn.test';

beforeEach(function (): void {
    config([
        'filesystems.disks.spaces.throw' => true,
        'filesystems.disks.spaces.url' => AUDIO_CDN,
        'filesystems.disks.spaces.bucket' => 'ixora-buckets',
        'filesystems.disks.spaces.region' => 'tor1',
        'filesystems.disks.spaces.endpoint' => 'https://tor1.digitaloceanspaces.com',
    ]);
    Storage::fake('spaces');
});

function audioActAs(string $role = 'admin'): User
{
    $user = User::factory()->create([
        'firebase_uid' => 'fb-audio-'.$role.'-'.uniqid(),
        'role' => $role === 'admin' ? 'admin' : 'user',
        'admin_access_status' => $role === 'admin' ? 'approved' : 'none',
    ]);

    $jwt = Mockery::mock(UnencryptedToken::class);
    $jwt->shouldReceive('claims')->andReturn(new DataSet([
        'sub' => $user->firebase_uid,
        'email' => $user->email,
        'name' => $user->name,
    ], 'e30.'));

    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->with('tok')->andReturn($jwt));

    return $user;
}

/** @return array<string, string> */
function audioHeaders(): array
{
    return ['Authorization' => 'Bearer tok', 'Accept' => 'application/json'];
}

function audioUpload(string $name = 'clip.mp3'): UploadedFile
{
    return UploadedFile::fake()->create($name, 50)->mimeType('audio/mpeg');
}

/** Sound whose version $version is published (object + revision row), like the worker would leave it. */
function audioReadySound(int $version = 1, array $overrides = []): Sound
{
    $sound = Sound::query()->create($overrides + [
        'name' => 'Rain',
        'category' => 'nature',
        'file_url' => '',
        'duration' => 10,
        'tags' => ['rain'],
        'is_active' => true,
        'audio_last_reserved_version' => $version,
    ]);

    $key = "sounds/{$sound->id}/audio/v{$version}/distribution.m4a";
    Storage::disk('spaces')->put($key, "published-v{$version}");
    $sound->forceFill([
        'file_url' => AUDIO_CDN.'/'.$key,
        'audio_version' => $version,
        'audio_status' => 'ready',
    ])->save();

    SoundAudioRevision::query()->create([
        'sound_id' => $sound->id,
        'version' => $version,
        'status' => 'ready',
        'source_key' => "sounds/{$sound->id}/audio/sources/v{$version}/source.mp3",
        'distribution_key' => $key,
    ]);
    Storage::disk('spaces')->put("sounds/{$sound->id}/audio/sources/v{$version}/source.mp3", 'source-bytes');

    return $sound->refresh();
}

/** Reserves $version for the sound and stores its private source, as the submission service does. */
function audioQueuedRevision(Sound $sound, int $version, string $status = 'queued'): SoundAudioRevision
{
    $sound->forceFill([
        'audio_last_reserved_version' => max((int) $sound->audio_last_reserved_version, $version),
    ])->save();

    $sourceKey = "sounds/{$sound->id}/audio/sources/v{$version}/source.mp3";
    Storage::disk('spaces')->put($sourceKey, 'source-bytes');

    return SoundAudioRevision::query()->create([
        'sound_id' => $sound->id,
        'version' => $version,
        'status' => $status,
        'source_key' => $sourceKey,
        'queued_at' => now(),
    ]);
}

/**
 * Proxy over the faked Spaces disk: everything passes through, expectations can intercept calls.
 * (The Spaces service itself is final, so the seam is the disk.)
 */
function audioSpyDisk(): MockInterface
{
    $spy = Mockery::mock(Storage::disk('spaces'));
    Storage::set('spaces', $spy);

    return $spy;
}

function audioProcess(SoundAudioRevision $revision): void
{
    app(SoundAudioProcessor::class)->process($revision->id);
}

// ---------------------------------------------------------------------------------------------------------------------
// AUDIO-03A / 03B — contract, upload, replacement
// ---------------------------------------------------------------------------------------------------------------------

test('create stores a private source, enqueues processing and does not publish the original', function (): void {
    Queue::fake();
    audioActAs();

    $real = Storage::disk('spaces');
    $writes = [];
    audioSpyDisk()->shouldReceive('put')->andReturnUsing(function (string $path, $contents, $options = []) use ($real, &$writes) {
        $writes[$path] = $options;

        return $real->put($path, $contents, $options);
    });

    $response = test()->post('/api/admin/sounds', [
        'name' => 'Admin sound',
        'category' => 'Catalog',
        'duration_seconds' => 90,
        'tags' => ['ambient'],
        'audio_file' => audioUpload(),
        'thumbnail_file' => UploadedFile::fake()->create('t.png', 8)->mimeType('image/png'),
    ], audioHeaders());

    $response->assertCreated()
        ->assertJsonPath('data.file_url', '')
        ->assertJsonPath('data.audio_version', null)
        ->assertJsonPath('data.audio_status', 'pending')
        ->assertJsonPath('data.audio_processing.status', 'queued')
        ->assertJsonPath('data.audio_processing.version', 1);

    $sound = Sound::query()->firstOrFail();
    $source = "sounds/{$sound->id}/audio/sources/v1/source.mp3";

    Storage::disk('spaces')->assertExists($source);
    Storage::disk('spaces')->assertMissing("sounds/{$sound->id}/audio/original.mp3");
    expect($writes[$source]['visibility'])->toBe('private');

    $revision = SoundAudioRevision::query()->firstOrFail();
    expect($revision->status)->toBe(AudioRevisionStatus::Queued)
        ->and($revision->dispatched_at)->not->toBeNull();

    Queue::assertPushed(
        ProcessSoundAudioRevision::class,
        fn (ProcessSoundAudioRevision $job): bool => $job->revisionId === $revision->id && $job->queue === 'audio',
    );
});

test('a sound without published audio is hidden from regular users but visible to admins', function (): void {
    $admin = audioActAs('user');
    Sound::query()->create([
        'name' => 'Pending', 'category' => 'x', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 1,
    ]);
    $ready = audioReadySound();

    $ids = test()->getJson('/api/sounds', audioHeaders())->assertOk()->json('data.*.id');
    expect($ids)->toBe([$ready->id]);

    audioActAs('admin');
    expect(test()->getJson('/api/sounds', audioHeaders())->assertOk()->json('data'))->toHaveCount(2);
});

test('a sound with pending audio cannot be attached to a vibe', function (): void {
    $user = audioActAs('user');
    $vibe = Vibe::query()->create(['user_id' => $user->id, 'name' => 'V', 'description' => null, 'is_active' => true]);
    $pending = Sound::query()->create([
        'name' => 'Pending', 'category' => 'x', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 1,
    ]);

    test()->postJson("/api/vibes/{$vibe->id}/sounds", ['sound_id' => $pending->id], audioHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sound_id']);
});

test('replacement reserves the next version and leaves the published audio untouched', function (): void {
    Queue::fake();
    audioActAs();
    $sound = audioReadySound(1);
    $publishedUrl = $sound->file_url;

    test()->post("/api/admin/sounds/{$sound->id}/audio", ['audio_file' => audioUpload()], audioHeaders())
        ->assertStatus(202)
        ->assertJsonPath('data.file_url', $publishedUrl)
        ->assertJsonPath('data.audio_version', 1)
        ->assertJsonPath('data.audio_processing.status', 'queued')
        ->assertJsonPath('data.audio_processing.version', 2);

    $sound->refresh();
    expect($sound->file_url)->toBe($publishedUrl)
        ->and($sound->audio_version)->toBe(1)
        ->and($sound->audio_last_reserved_version)->toBe(2);

    // Published bytes are never rewritten; the new upload lives under its own versioned key.
    expect(Storage::disk('spaces')->get("sounds/{$sound->id}/audio/v1/distribution.m4a"))->toBe('published-v1');
    Storage::disk('spaces')->assertExists("sounds/{$sound->id}/audio/sources/v2/source.mp3");
    Queue::assertPushed(ProcessSoundAudioRevision::class);
});

test('replacement rejects non-audio uploads before reserving a version', function (): void {
    Queue::fake();
    audioActAs();
    $sound = audioReadySound(1);

    test()->post("/api/admin/sounds/{$sound->id}/audio", [
        'audio_file' => UploadedFile::fake()->create('photo.jpg', 8)->mimeType('image/jpeg'),
    ], audioHeaders())->assertUnprocessable();

    expect($sound->refresh()->audio_last_reserved_version)->toBe(1);
    Queue::assertNothingPushed();
});

test('regular users cannot replace audio', function (): void {
    Queue::fake();
    audioActAs('user');
    $sound = audioReadySound(1);

    test()->post("/api/admin/sounds/{$sound->id}/audio", ['audio_file' => audioUpload()], audioHeaders())
        ->assertForbidden();
});

test('when the job cannot be enqueued, create answers 503 and leaves nothing behind', function (): void {
    audioActAs();
    config(['audio.queue.connection' => 'connection-that-does-not-exist']);

    test()->post('/api/admin/sounds', [
        'name' => 'Admin sound', 'category' => 'Catalog', 'duration_seconds' => 90, 'tags' => ['a'],
        'audio_file' => audioUpload(),
        'thumbnail_file' => UploadedFile::fake()->create('t.png', 8)->mimeType('image/png'),
    ], audioHeaders())
        ->assertStatus(503)
        ->assertJsonPath('code', 'audio_not_enqueued');

    expect(Sound::query()->count())->toBe(0)
        ->and(SoundAudioRevision::query()->count())->toBe(0)
        ->and(Storage::disk('spaces')->allFiles())->toBe([]);
});

test('when the job cannot be enqueued, replacement answers 503 and keeps the current version', function (): void {
    audioActAs();
    $sound = audioReadySound(1);
    $publishedUrl = $sound->file_url;
    config(['audio.queue.connection' => 'connection-that-does-not-exist']);

    test()->post("/api/admin/sounds/{$sound->id}/audio", ['audio_file' => audioUpload()], audioHeaders())
        ->assertStatus(503)
        ->assertJsonPath('code', 'audio_not_enqueued');

    $sound->refresh();
    expect($sound->file_url)->toBe($publishedUrl)
        ->and($sound->audio_version)->toBe(1);

    $failed = SoundAudioRevision::query()->where('version', 2)->firstOrFail();
    expect($failed->status)->toBe(AudioRevisionStatus::Failed)
        ->and($failed->error_code)->toBe('dispatch_failed');
    Storage::disk('spaces')->assertMissing("sounds/{$sound->id}/audio/sources/v2/source.mp3");
    Storage::disk('spaces')->assertExists("sounds/{$sound->id}/audio/v1/distribution.m4a");
});

test('PATCH accepts descriptive edits and an echoed file_url but never changes the audio', function (): void {
    audioActAs();
    $sound = audioReadySound(1);
    $url = $sound->file_url;

    test()->patchJson("/api/sounds/{$sound->id}", ['name' => 'Renamed', 'file_url' => $url, 'tags' => ['x']], audioHeaders())
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.audio_version', 1)
        ->assertJsonPath('data.file_url', $url);

    test()->patchJson("/api/sounds/{$sound->id}", ['name' => 'Again', 'file_url' => null], audioHeaders())
        ->assertOk()
        ->assertJsonPath('data.file_url', $url);

    test()->patchJson("/api/sounds/{$sound->id}", ['file_url' => 'https://evil.example/other.mp3'], audioHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file_url']);

    $sound->refresh();
    expect($sound->file_url)->toBe($url)
        ->and($sound->audio_version)->toBe(1)
        ->and($sound->name)->toBe('Again');
});

test('PATCH on a sound whose audio is still pending does not fail on the empty file_url', function (): void {
    audioActAs();
    $sound = Sound::query()->create([
        'name' => 'P', 'category' => 'x', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 1,
    ]);

    test()->patchJson("/api/sounds/{$sound->id}", ['name' => 'P2', 'file_url' => null], audioHeaders())
        ->assertOk()
        ->assertJsonPath('data.name', 'P2')
        ->assertJsonPath('data.audio_status', 'pending');
});

test('the vibe sounds payload carries audio_version next to the immutable file_url', function (): void {
    $user = audioActAs('user');
    $sound = audioReadySound(3);
    $vibe = Vibe::query()->create(['user_id' => $user->id, 'name' => 'V', 'description' => null, 'is_active' => true]);
    $vibe->sounds()->attach($sound->id, ['volume' => 80, 'loop' => true, 'sort_order' => 0]);

    $row = test()->getJson("/api/vibes/{$vibe->id}/sounds", audioHeaders())->assertOk()->json('data.0');

    expect($row['audio_version'])->toBe(3)
        ->and($row['file_url'])->toBe(AUDIO_CDN."/sounds/{$sound->id}/audio/v3/distribution.m4a");
});

// ---------------------------------------------------------------------------------------------------------------------
// AUDIO-03C — processing
// ---------------------------------------------------------------------------------------------------------------------

test('processing publishes a versioned public distribution and keeps the source', function (): void {
    $sound = Sound::query()->create(['name' => 'S', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 10]);
    $revision = audioQueuedRevision($sound, 1);

    $real = Storage::disk('spaces');
    $seen = [];
    audioSpyDisk()->shouldReceive('put')->andReturnUsing(function (string $path, $contents, $options = []) use ($real, &$seen) {
        $seen[$path] = $options;

        return $real->put($path, $contents, $options);
    });

    audioProcess($revision);

    $key = "sounds/{$sound->id}/audio/v1/distribution.m4a";
    $sound->refresh();
    $revision->refresh();

    expect($sound->file_url)->toBe(AUDIO_CDN.'/'.$key)
        ->and($sound->audio_version)->toBe(1)
        ->and($sound->audio_status->value)->toBe('ready')
        ->and($revision->status)->toBe(AudioRevisionStatus::Ready)
        ->and($revision->distribution_key)->toBe($key)
        ->and($revision->attempts)->toBe(1)
        ->and($seen[$key]['visibility'])->toBe('public')
        ->and($seen[$key]['CacheControl'])->toContain('immutable');

    Storage::disk('spaces')->assertExists($key);
    Storage::disk('spaces')->assertExists($revision->source_key);
});

test('the version only advances after the distribution object exists', function (): void {
    $sound = Sound::query()->create(['name' => 'S', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 10]);
    $revision = audioQueuedRevision($sound, 1);

    $observed = null;
    test()->audioTranscoder->onTranscode = function () use ($sound, &$observed): void {
        $observed = [$sound->fresh()->audio_version, $sound->fresh()->file_url];
    };

    audioProcess($revision);

    expect($observed)->toBe([null, ''])
        ->and($sound->refresh()->audio_version)->toBe(1);
});

test('a retryable failure requeues the revision, keeps the previous version and succeeds on retry', function (): void {
    $sound = audioReadySound(1);
    $previousUrl = $sound->file_url;
    $revision = audioQueuedRevision($sound, 2);

    test()->audioTranscoder->transcodeError = AudioProcessingException::transient('transcode_failed', 'ffmpeg exploded');

    expect(fn () => audioProcess($revision))->toThrow(AudioProcessingException::class);

    $revision->refresh();
    expect($revision->status)->toBe(AudioRevisionStatus::Queued)
        ->and($revision->error_code)->toBe('transcode_failed')
        ->and($revision->attempts)->toBe(1)
        ->and($sound->refresh()->file_url)->toBe($previousUrl)
        ->and($sound->audio_version)->toBe(1);
    Storage::disk('spaces')->assertMissing("sounds/{$sound->id}/audio/v2/distribution.m4a");

    test()->audioTranscoder->transcodeError = null;
    audioProcess($revision);

    expect($revision->refresh()->status)->toBe(AudioRevisionStatus::Ready)
        ->and($revision->attempts)->toBe(2)
        ->and($sound->refresh()->audio_version)->toBe(2);
});

test('an invalid source fails permanently without retries and keeps the previous version', function (): void {
    $sound = audioReadySound(1);
    $previousUrl = $sound->file_url;
    $revision = audioQueuedRevision($sound, 2);

    test()->audioTranscoder->probeSourceError = AudioProcessingException::permanent('unreadable_media', 'not audio');

    audioProcess($revision);

    $revision->refresh();
    expect($revision->status)->toBe(AudioRevisionStatus::Failed)
        ->and($revision->error_code)->toBe('unreadable_media')
        ->and($revision->finished_at)->not->toBeNull()
        ->and($sound->refresh()->file_url)->toBe($previousUrl)
        ->and($sound->audio_version)->toBe(1)
        ->and(test()->audioTranscoder->transcodeCalls)->toBe(0);
});

test('sources without an audio stream, or that are too short, are rejected', function (): void {
    $sound = Sound::query()->create(['name' => 'S', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 1]);

    test()->audioTranscoder->sourceAudioStreams = 0;
    $noAudio = audioQueuedRevision($sound, 1);
    audioProcess($noAudio);
    expect($noAudio->refresh()->error_code)->toBe('no_audio_stream');

    test()->audioTranscoder->sourceAudioStreams = 1;
    test()->audioTranscoder->sourceDurationMs = 10;
    $tooShort = audioQueuedRevision($sound, 2);
    audioProcess($tooShort);
    expect($tooShort->refresh()->error_code)->toBe('invalid_duration')
        ->and($sound->refresh()->file_url)->toBe('');
});

test('an output that does not match the profile is never published', function (): void {
    $sound = Sound::query()->create(['name' => 'S', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 10]);

    test()->audioTranscoder->outputCodec = 'mp3';
    $wrongCodec = audioQueuedRevision($sound, 1);
    audioProcess($wrongCodec);
    expect($wrongCodec->refresh()->error_code)->toBe('invalid_output');

    test()->audioTranscoder->outputCodec = 'aac';
    test()->audioTranscoder->outputDurationMs = 3_000;
    $wrongDuration = audioQueuedRevision($sound, 2);
    audioProcess($wrongDuration);
    expect($wrongDuration->refresh()->error_code)->toBe('duration_mismatch')
        ->and($sound->refresh()->file_url)->toBe('')
        ->and($sound->audio_version)->toBeNull();
    expect(Storage::disk('spaces')->allFiles())->not->toContain("sounds/{$sound->id}/audio/v1/distribution.m4a");
});

test('a missing source object is a permanent failure', function (): void {
    $sound = Sound::query()->create(['name' => 'S', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 10]);
    $revision = audioQueuedRevision($sound, 1);
    Storage::disk('spaces')->delete($revision->source_key);

    audioProcess($revision);

    expect($revision->refresh()->status)->toBe(AudioRevisionStatus::Failed)
        ->and($revision->error_code)->toBe('source_missing');
});

test('running a finished or already claimed revision again is a no-op', function (): void {
    $sound = Sound::query()->create(['name' => 'S', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 10]);
    $revision = audioQueuedRevision($sound, 1);

    audioProcess($revision);
    audioProcess($revision);
    expect(test()->audioTranscoder->transcodeCalls)->toBe(1)
        ->and($revision->refresh()->attempts)->toBe(1);

    // A fresh `processing` claim held by another worker must not be stolen.
    $other = audioQueuedRevision($sound, 2, 'processing');
    $other->forceFill(['heartbeat_at' => now(), 'attempts' => 1])->save();
    audioProcess($other);
    expect(test()->audioTranscoder->transcodeCalls)->toBe(1)
        ->and($other->refresh()->attempts)->toBe(1);
});

test('only the highest version becomes current when replacements finish out of order', function (): void {
    $sound = Sound::query()->create(['name' => 'S', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 10]);
    $v1 = audioQueuedRevision($sound, 1);
    $v2 = audioQueuedRevision($sound, 2);

    audioProcess($v2);
    audioProcess($v1);

    $sound->refresh();
    expect($sound->audio_version)->toBe(2)
        ->and($sound->file_url)->toBe(AUDIO_CDN."/sounds/{$sound->id}/audio/v2/distribution.m4a")
        ->and($v1->refresh()->status)->toBe(AudioRevisionStatus::Superseded)
        ->and($v2->refresh()->status)->toBe(AudioRevisionStatus::Ready);
    Storage::disk('spaces')->assertMissing("sounds/{$sound->id}/audio/v1/distribution.m4a");
});

test('a revision that loses the race while transcoding is superseded and its object discarded', function (): void {
    $sound = Sound::query()->create(['name' => 'S', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 10]);
    $v1 = audioQueuedRevision($sound, 1);
    $v2 = audioQueuedRevision($sound, 2);

    // While v1 is transcoding, v2 is fully processed and published by another worker.
    test()->audioTranscoder->onTranscode = function () use ($v2): void {
        test()->audioTranscoder->onTranscode = null;
        audioProcess($v2);
    };

    audioProcess($v1);

    expect($v1->refresh()->status)->toBe(AudioRevisionStatus::Superseded)
        ->and($sound->refresh()->audio_version)->toBe(2);
    Storage::disk('spaces')->assertMissing("sounds/{$sound->id}/audio/v1/distribution.m4a");
    Storage::disk('spaces')->assertExists("sounds/{$sound->id}/audio/v2/distribution.m4a");
});

test('deleting the sound while it is processing leaves no distribution object behind', function (): void {
    $sound = Sound::query()->create(['name' => 'S', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 10]);
    $revision = audioQueuedRevision($sound, 1);

    test()->audioTranscoder->onTranscode = fn () => $sound->delete();

    audioProcess($revision);

    expect(Sound::query()->count())->toBe(0);
    Storage::disk('spaces')->assertMissing("sounds/{$sound->id}/audio/v1/distribution.m4a");
});

test('the job is bound to the dedicated queue with timeouts below the connection retry_after', function (): void {
    $job = new ProcessSoundAudioRevision(7);

    expect($job->queue)->toBe('audio')
        ->and($job->tries)->toBe(3)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->backoff())->toBe([60, 300])
        ->and((int) config('queue.connections.database_audio.retry_after'))->toBeGreaterThan($job->timeout)
        ->and((int) config('audio.recovery.stale_after_seconds'))->toBeGreaterThan($job->timeout)
        ->and((int) config('audio.recovery.stale_after_seconds'))->toBeLessThan((int) config('queue.connections.database_audio.retry_after'))
        ->and(config('queue.connections.database_audio.table'))->toBe(config('queue.connections.database.table'));
});

test('when the queue gives up, the job marks the revision failed and the old version survives', function (): void {
    $sound = audioReadySound(1);
    $revision = audioQueuedRevision($sound, 2);

    (new ProcessSoundAudioRevision($revision->id))->failed(new RuntimeException('timed out'));

    expect($revision->refresh()->status)->toBe(AudioRevisionStatus::Failed)
        ->and($revision->error_code)->toBe('processing_failed')
        ->and($sound->refresh()->audio_version)->toBe(1);
});

// ---------------------------------------------------------------------------------------------------------------------
// Recovery
// ---------------------------------------------------------------------------------------------------------------------

test('recovery re-dispatches stuck and lost revisions and fails exhausted ones', function (): void {
    Queue::fake();
    $sound = Sound::query()->create(['name' => 'S', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 10]);

    $stale = audioQueuedRevision($sound, 1, 'processing');
    $stale->forceFill(['heartbeat_at' => now()->subHour(), 'attempts' => 1])->save();

    $fresh = audioQueuedRevision($sound, 2, 'processing');
    $fresh->forceFill(['heartbeat_at' => now(), 'attempts' => 1])->save();

    $undispatched = audioQueuedRevision($sound, 3);
    $undispatched->forceFill(['queued_at' => now()->subMinutes(10), 'dispatched_at' => null])->save();

    $exhausted = audioQueuedRevision($sound, 4, 'processing');
    $exhausted->forceFill(['heartbeat_at' => now()->subHour(), 'attempts' => 5])->save();

    $done = audioQueuedRevision($sound, 5, 'ready');

    $result = app(SoundAudioRecovery::class)->run();

    expect($result['requeued'])->toBe(2)
        ->and($result['failed'])->toBe(1)
        ->and($stale->refresh()->status)->toBe(AudioRevisionStatus::Queued)
        ->and($fresh->refresh()->status)->toBe(AudioRevisionStatus::Processing)
        ->and($exhausted->refresh()->status)->toBe(AudioRevisionStatus::Failed)
        ->and($exhausted->error_code)->toBe('max_attempts_exceeded')
        ->and($done->refresh()->status)->toBe(AudioRevisionStatus::Ready);

    Queue::assertPushed(ProcessSoundAudioRevision::class, 2);
});

test('a stale processing revision can be claimed again by the next worker', function (): void {
    $sound = Sound::query()->create(['name' => 'S', 'category' => 'c', 'file_url' => '', 'audio_status' => 'pending', 'duration' => 10]);
    $revision = audioQueuedRevision($sound, 1, 'processing');
    $revision->forceFill(['heartbeat_at' => now()->subHour(), 'attempts' => 1])->save();

    audioProcess($revision);

    expect($revision->refresh()->status)->toBe(AudioRevisionStatus::Ready)
        ->and($revision->attempts)->toBe(2);
});

// ---------------------------------------------------------------------------------------------------------------------
// Deletion
// ---------------------------------------------------------------------------------------------------------------------

/** @return list<string> */
function audioSoundObjects(Sound $sound): array
{
    $legacy = "sounds/{$sound->id}/audio/original.mp3";
    Storage::disk('spaces')->put($legacy, 'legacy');
    Storage::disk('spaces')->put("sounds/{$sound->id}/audio/v7/distribution.m4a", 'orphan-from-crashed-upload');
    Storage::disk('spaces')->put("sounds/{$sound->id}/thumbnail/thumbnail.png", 'thumb');

    return Storage::disk('spaces')->allFiles("sounds/{$sound->id}");
}

test('deleting a sound removes every version, source, legacy file and orphan', function (): void {
    audioActAs();
    $sound = audioReadySound(1, ['thumbnail_url' => null]);
    audioQueuedRevision($sound, 2, 'failed');
    $objects = audioSoundObjects($sound);
    expect($objects)->toHaveCount(6);

    test()->deleteJson("/api/sounds/{$sound->id}", [], audioHeaders())
        ->assertOk()
        ->assertJson(['message' => 'Sound deleted.', 'storage_cleanup' => 'completed']);

    expect(Sound::query()->count())->toBe(0)
        ->and(Storage::disk('spaces')->allFiles("sounds/{$sound->id}"))->toBe([])
        ->and(StorageDeletionTask::query()->where('status', 'pending')->count())->toBe(0);
});

test('a crash between the database delete and Spaces is recovered from the persisted tasks, idempotently', function (): void {
    $sound = audioReadySound(1);
    audioQueuedRevision($sound, 2, 'failed');
    audioSoundObjects($sound);
    $soundId = $sound->id;

    // Simulate: tasks + row deletion committed, process died before touching Spaces.
    DB::transaction(function () use ($sound): void {
        app(StorageDeletionService::class)->scheduleForSound($sound);
        $sound->delete();
    });

    expect(Storage::disk('spaces')->allFiles("sounds/{$soundId}"))->not->toBe([])
        ->and(StorageDeletionTask::query()->where('status', 'pending')->count())->toBeGreaterThan(0);

    $first = app(StorageDeletionService::class)->runPending();
    expect($first['pending'])->toBe(0)
        ->and(Storage::disk('spaces')->allFiles("sounds/{$soundId}"))->toBe([]);

    $again = app(StorageDeletionService::class)->runPending();
    expect($again)->toBe(['done' => 0, 'pending' => 0]);
});

test('storage failures keep the tasks pending, never claim completion, and are retried', function (): void {
    Queue::fake();
    audioActAs();
    $sound = audioReadySound(1);
    audioSoundObjects($sound);

    $real = Storage::disk('spaces');
    $failing = true;
    audioSpyDisk()->shouldReceive('delete')->andReturnUsing(
        function ($paths) use ($real, &$failing) {
            return $failing ? false : $real->delete($paths);
        },
    );

    test()->deleteJson("/api/sounds/{$sound->id}", [], audioHeaders())
        ->assertOk()
        ->assertJsonPath('storage_cleanup', 'pending');

    expect(Sound::query()->count())->toBe(0)
        ->and(StorageDeletionTask::query()->where('status', 'pending')->count())->toBeGreaterThan(0)
        ->and(Storage::disk('spaces')->allFiles("sounds/{$sound->id}"))->not->toBe([]);
    Queue::assertPushed(PurgeStorageDeletionTasks::class);

    $failing = false;
    $recovery = app(SoundAudioRecovery::class)->run();

    expect($recovery['deletions_pending'])->toBe(0)
        ->and(Storage::disk('spaces')->allFiles("sounds/{$sound->id}"))->toBe([])
        ->and(StorageDeletionTask::query()->where('status', 'pending')->count())->toBe(0);
});

test('when the queue gives up after a retryable failure, the specific error code is kept', function (): void {
    $sound = audioReadySound(1);
    $revision = audioQueuedRevision($sound, 2);
    test()->audioTranscoder->transcodeError = AudioProcessingException::transient('transcode_failed', 'ffmpeg exploded');

    expect(fn () => audioProcess($revision))->toThrow(AudioProcessingException::class);
    (new ProcessSoundAudioRevision($revision->id))->failed(new RuntimeException('max attempts'));

    expect($revision->refresh()->status)->toBe(AudioRevisionStatus::Failed)
        ->and($revision->error_code)->toBe('transcode_failed')
        ->and($revision->error_message)->toContain('ffmpeg exploded')
        ->and($sound->refresh()->audio_version)->toBe(1);
});

test('recovery removes work directories left behind by killed workers, but not recent ones', function (): void {
    $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'audio-sweep-'.uniqid();
    mkdir($base, 0777, true);
    config(['audio.ffmpeg.temp_directory' => $base]);

    $old = $base.DIRECTORY_SEPARATOR.'ixora-audio-1-aaaa';
    $recent = $base.DIRECTORY_SEPARATOR.'ixora-audio-2-bbbb';
    $unrelated = $base.DIRECTORY_SEPARATOR.'something-else';
    foreach ([$old, $recent, $unrelated] as $dir) {
        mkdir($dir);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'source.wav', 'x');
    }
    touch($old, time() - 7 * 3600);
    touch($unrelated, time() - 7 * 3600);

    $result = app(SoundAudioRecovery::class)->run();

    expect($result['temp_dirs_removed'])->toBe(1)
        ->and(is_dir($old))->toBeFalse()
        ->and(is_dir($recent))->toBeTrue()
        ->and(is_dir($unrelated))->toBeTrue();

    File::deleteDirectory($base);
});
