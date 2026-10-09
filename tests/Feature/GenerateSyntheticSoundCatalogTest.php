<?php

declare(strict_types=1);

use App\Models\Sound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('GD extension required for synthetic thumbnail generation.');
    }

    Config::set('filesystems.disks.spaces', [
        'driver' => 's3',
        'key' => 'test',
        'secret' => 'test',
        'region' => 'tor1',
        'bucket' => 'ixora-buckets',
        'endpoint' => 'https://tor1.digitaloceanspaces.com',
        'url' => 'https://ixora-buckets.tor1.cdn.digitaloceanspaces.com',
        'use_path_style_endpoint' => false,
        'throw' => true,
    ]);

    Config::set('services', array_merge(config('services', []), []));
    putenv('DO_SPACES_KEY=test-key');
    putenv('DO_SPACES_SECRET=test-secret');
    putenv('DO_SPACES_BUCKET=ixora-buckets');
    $_ENV['DO_SPACES_KEY'] = 'test-key';
    $_ENV['DO_SPACES_SECRET'] = 'test-secret';
    $_ENV['DO_SPACES_BUCKET'] = 'ixora-buckets';

    Storage::fake('spaces');
});

test('generate synthetic catalog with limit creates three sounds with assets', function (): void {
    $this->artisan('sounds:generate-synthetic-catalog', ['--limit' => 3])
        ->assertSuccessful();

    expect(Sound::query()->count())->toBe(3);

    $sounds = Sound::query()->orderBy('id')->get();
    foreach ($sounds as $sound) {
        expect($sound->file_url)->not->toBeEmpty()
            ->and($sound->thumbnail_url)->not->toBeNull()->not->toBeEmpty()
            ->and($sound->is_active)->toBeTrue()
            ->and($sound->tags)->toContain('synthetic-audio');
    }
});

test('generate synthetic catalog is idempotent for the same limit', function (): void {
    $this->artisan('sounds:generate-synthetic-catalog', ['--limit' => 3])
        ->assertSuccessful();

    $countAfterFirst = Sound::query()->count();

    $this->artisan('sounds:generate-synthetic-catalog', ['--limit' => 3])
        ->assertSuccessful();

    expect(Sound::query()->count())->toBe($countAfterFirst);
});
