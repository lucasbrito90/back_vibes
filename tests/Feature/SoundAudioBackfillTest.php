<?php

declare(strict_types=1);

use App\Models\Sound;
use App\Models\SoundAudioRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

function backfillSound(string $url, string $name = 'S'): Sound
{
    return Sound::query()->create(['name' => $name, 'category' => 'c', 'file_url' => $url, 'duration' => 5]);
}

/** @return array{canonical: Sound, other: Sound, missing: Sound, empty: Sound, external: Sound} */
function backfillCatalog(): array
{
    $canonical = backfillSound('https://cdn.test/sounds/1/audio/original.mp3', 'canonical');
    Storage::disk('spaces')->put('sounds/1/audio/original.mp3', 'bytes');

    $other = backfillSound('https://cdn.test/legacy/rain.mp3', 'other');
    Storage::disk('spaces')->put('legacy/rain.mp3', 'bytes');

    $missing = backfillSound('https://cdn.test/sounds/3/audio/original.mp3', 'missing');

    $empty = backfillSound('https://cdn.test/sounds/4/audio/original.mp3', 'empty');
    Storage::disk('spaces')->put('sounds/4/audio/original.mp3', '');

    $external = backfillSound('https://elsewhere.example/a.mp3', 'external');

    return compact('canonical', 'other', 'missing', 'empty', 'external');
}

test('dry run reports every outcome and writes nothing', function (): void {
    backfillCatalog();

    test()->artisan('sounds:audio-backfill')->assertSuccessful();

    expect(Sound::query()->whereNotNull('audio_version')->count())->toBe(0)
        ->and(SoundAudioRevision::query()->count())->toBe(0)
        ->and(Sound::query()->where('audio_status', 'legacy')->count())->toBe(5);
});

test('dry run issues only SELECT statements against the database', function (): void {
    backfillCatalog();

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower(ltrim($query->sql));
    });

    test()->artisan('sounds:audio-backfill')->assertSuccessful();

    expect($statements)->not->toBe([]);
    $writes = array_filter($statements, fn (string $sql): bool => ! str_starts_with($sql, 'select'));
    expect(array_values($writes))->toBe([]);
});

test('apply versions only the sounds whose object really exists', function (): void {
    $c = backfillCatalog();

    test()->artisan('sounds:audio-backfill', ['--apply' => true])->assertSuccessful();

    foreach (['canonical', 'other'] as $name) {
        $sound = $c[$name]->refresh();
        expect($sound->audio_version)->toBe(1)
            ->and($sound->audio_status->value)->toBe('ready')
            ->and($sound->audio_last_reserved_version)->toBe(1);
    }

    foreach (['missing', 'empty', 'external'] as $name) {
        $sound = $c[$name]->refresh();
        expect($sound->audio_version)->toBeNull()
            ->and($sound->audio_status->value)->toBe('legacy');
    }

    expect(SoundAudioRevision::query()->count())->toBe(2)
        ->and(SoundAudioRevision::query()->where('sound_id', $c['canonical']->id)->first()->distribution_key)
        ->toBe('sounds/1/audio/original.mp3');
});

test('apply is idempotent', function (): void {
    $c = backfillCatalog();

    test()->artisan('sounds:audio-backfill', ['--apply' => true])->assertSuccessful();
    test()->artisan('sounds:audio-backfill', ['--apply' => true])->assertSuccessful();

    expect(SoundAudioRevision::query()->count())->toBe(2)
        ->and($c['canonical']->refresh()->audio_version)->toBe(1)
        ->and($c['canonical']->audio_last_reserved_version)->toBe(1);
});

test('the sound option limits the inventory', function (): void {
    $c = backfillCatalog();

    test()->artisan('sounds:audio-backfill', ['--apply' => true, '--sound' => [$c['other']->id]])->assertSuccessful();

    expect($c['other']->refresh()->audio_version)->toBe(1)
        ->and($c['canonical']->refresh()->audio_version)->toBeNull();
});
