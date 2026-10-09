<?php

declare(strict_types=1);

/*
 * Integration tests that run the REAL ffmpeg/ffprobe binaries through the whole pipeline
 * (HTTP upload -> private source -> job -> transcode -> validate -> versioned object -> publish).
 *
 * Storage and database stay fake/isolated (faked "spaces" disk, in-memory SQLite). They are skipped when
 * ffmpeg/ffprobe are not available. They prove the binaries and the transcoder work; they do NOT prove the
 * DigitalOcean worker, Spaces or PostgreSQL behave the same way.
 */

use App\Models\Sound;
use App\Models\SoundAudioRevision;
use App\Models\User;
use App\Services\Audio\AudioRevisionStatus;
use App\Services\Audio\AudioTranscoding;
use App\Services\Audio\FfmpegAudioTranscoder;
use App\Services\Audio\SoundAudioProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function realFfmpegAvailable(): bool
{
    foreach (['ffmpeg', 'ffprobe'] as $binary) {
        try {
            $process = new Process([$binary, '-version']);
            $process->run();
            if (! $process->isSuccessful()) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }
    }

    return true;
}

beforeEach(function (): void {
    if (! realFfmpegAvailable()) {
        test()->markTestSkipped('ffmpeg/ffprobe are not available.');
    }

    // Replace the suite-wide fake with the real transcoder for these tests only.
    $this->app->bind(AudioTranscoding::class, FfmpegAudioTranscoder::class);

    config([
        'filesystems.disks.spaces.throw' => true,
        'filesystems.disks.spaces.url' => 'https://cdn.test',
    ]);
    Storage::fake('spaces');
});

/** Generates a real audio fixture with ffmpeg and returns its path. */
function realAudioFixture(string $name, array $inputArgs, array $outputArgs = []): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ixora-real-ffmpeg-tests';
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $path = $dir.DIRECTORY_SEPARATOR.$name;

    $process = new Process(['ffmpeg', '-nostdin', '-loglevel', 'error', '-y', ...$inputArgs, ...$outputArgs, $path]);
    $process->setTimeout(60);
    $process->mustRun();

    return $path;
}

function realUpload(string $path, string $mime): UploadedFile
{
    return new UploadedFile($path, basename($path), $mime, null, true);
}

function realAdminHeaders(): array
{
    $user = User::factory()->create(['firebase_uid' => 'fb-real-ffmpeg', 'role' => 'admin', 'admin_access_status' => 'approved']);
    $jwt = Mockery::mock(UnencryptedToken::class);
    $jwt->shouldReceive('claims')->andReturn(new DataSet(['sub' => $user->firebase_uid, 'email' => $user->email, 'name' => 'n'], 'e30.'));
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->with('tok')->andReturn($jwt));

    return ['Authorization' => 'Bearer tok', 'Accept' => 'application/json'];
}

/** Downloads an object from the faked disk and probes it with the real ffprobe. */
function realProbeStored(string $key): array
{
    $local = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ixora-probe-'.bin2hex(random_bytes(4)).'.'.pathinfo($key, PATHINFO_EXTENSION);
    file_put_contents($local, Storage::disk('spaces')->get($key));
    try {
        $probe = app(AudioTranscoding::class)->probe($local);

        return ['codec' => $probe->codec, 'ms' => $probe->durationMs, 'streams' => $probe->audioStreams, 'bytes' => filesize($local)];
    } finally {
        @unlink($local);
    }
}

test('real ffmpeg: upload through the API is transcoded, validated and published as v1', function (): void {
    $headers = realAdminHeaders();
    $mp3 = realAudioFixture('tone.mp3', ['-f', 'lavfi', '-i', 'sine=frequency=440:duration=3'], ['-c:a', 'libmp3lame', '-b:a', '128k']);

    test()->post('/api/admin/sounds', [
        'name' => 'Real', 'category' => 'c', 'duration_seconds' => 3, 'tags' => ['t'],
        'audio_file' => realUpload($mp3, 'audio/mpeg'),
        'thumbnail_file' => UploadedFile::fake()->create('t.png', 8)->mimeType('image/png'),
    ], $headers)->assertCreated();

    $sound = Sound::query()->firstOrFail();
    $key = "sounds/{$sound->id}/audio/v1/distribution.m4a";

    expect($sound->audio_version)->toBe(1)
        ->and($sound->audio_status->value)->toBe('ready')
        ->and($sound->file_url)->toBe('https://cdn.test/'.$key);

    $stored = realProbeStored($key);
    expect($stored['codec'])->toBe('aac')
        ->and($stored['streams'])->toBe(1)
        ->and(abs($stored['ms'] - 3000))->toBeLessThan(150);
    Storage::disk('spaces')->assertExists("sounds/{$sound->id}/audio/sources/v1/source.mp3");
});

test('real ffmpeg: a corrupt upload fails permanently and the published version survives', function (): void {
    $headers = realAdminHeaders();
    $good = realAudioFixture('good.wav', ['-f', 'lavfi', '-i', 'sine=frequency=330:duration=2']);

    test()->post('/api/admin/sounds', [
        'name' => 'Real', 'category' => 'c', 'duration_seconds' => 2, 'tags' => ['t'],
        'audio_file' => realUpload($good, 'audio/x-wav'),
        'thumbnail_file' => UploadedFile::fake()->create('t.png', 8)->mimeType('image/png'),
    ], $headers)->assertCreated();

    $sound = Sound::query()->firstOrFail();
    $v1Url = $sound->file_url;

    // A structurally valid WAV with zero samples: content sniffing accepts it as audio/x-wav, so it reaches the
    // worker, where ffprobe/duration validation must reject it. (Random bytes never get this far: the upload
    // validator already refuses them.)
    $empty = sys_get_temp_dir().DIRECTORY_SEPARATOR.'empty.wav';
    file_put_contents($empty, 'RIFF'.pack('V', 36).'WAVE'.'fmt '.pack('VvvVVvv', 16, 1, 1, 44100, 88200, 2, 16).'data'.pack('V', 0));

    test()->post("/api/admin/sounds/{$sound->id}/audio", ['audio_file' => realUpload($empty, 'audio/x-wav')], $headers)
        ->assertStatus(202);

    $revision = SoundAudioRevision::query()->where('version', 2)->firstOrFail();

    expect($revision->status)->toBe(AudioRevisionStatus::Failed)
        ->and($revision->error_code)->toBe('invalid_duration')
        ->and($sound->refresh()->file_url)->toBe($v1Url)
        ->and($sound->audio_version)->toBe(1);
    Storage::disk('spaces')->assertMissing("sounds/{$sound->id}/audio/v2/distribution.m4a");
});

test('real ffmpeg: replacing audio publishes v2 and keeps the v1 object untouched; reprocessing is a no-op', function (): void {
    $headers = realAdminHeaders();
    $first = realAudioFixture('first.wav', ['-f', 'lavfi', '-i', 'sine=frequency=220:duration=2']);
    $second = realAudioFixture('second.wav', ['-f', 'lavfi', '-i', 'sine=frequency=880:duration=4']);

    test()->post('/api/admin/sounds', [
        'name' => 'Real', 'category' => 'c', 'duration_seconds' => 2, 'tags' => ['t'],
        'audio_file' => realUpload($first, 'audio/x-wav'),
        'thumbnail_file' => UploadedFile::fake()->create('t.png', 8)->mimeType('image/png'),
    ], $headers)->assertCreated();

    $sound = Sound::query()->firstOrFail();
    $v1Key = "sounds/{$sound->id}/audio/v1/distribution.m4a";
    $v1Hash = md5((string) Storage::disk('spaces')->get($v1Key));

    test()->post("/api/admin/sounds/{$sound->id}/audio", ['audio_file' => realUpload($second, 'audio/x-wav')], $headers)
        ->assertStatus(202);

    $sound->refresh();
    $v2Key = "sounds/{$sound->id}/audio/v2/distribution.m4a";
    expect($sound->audio_version)->toBe(2)
        ->and($sound->file_url)->toBe('https://cdn.test/'.$v2Key)
        ->and(md5((string) Storage::disk('spaces')->get($v1Key)))->toBe($v1Hash)
        ->and(abs(realProbeStored($v2Key)['ms'] - 4000))->toBeLessThan(150);

    $revision = SoundAudioRevision::query()->where('version', 2)->firstOrFail();
    $before = md5((string) Storage::disk('spaces')->get($v2Key));
    app(SoundAudioProcessor::class)->process($revision->id);

    expect($revision->refresh()->attempts)->toBe(1)
        ->and(md5((string) Storage::disk('spaces')->get($v2Key)))->toBe($before)
        ->and($sound->refresh()->audio_version)->toBe(2);
});
