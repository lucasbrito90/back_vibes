<?php

declare(strict_types=1);

use App\Services\Storage\DigitalOceanSpacesService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
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
});

test('publicUrl builds CDN URL correctly', function (): void {
    $svc = new DigitalOceanSpacesService;

    expect($svc->publicUrl('sounds/1/audio/original.mp3'))
        ->toBe('https://ixora-buckets.tor1.cdn.digitaloceanspaces.com/sounds/1/audio/original.mp3');
});

test('publicUrl strips leading slash from key', function (): void {
    $svc = new DigitalOceanSpacesService;

    expect($svc->publicUrl('/sounds/1/audio/original.mp3'))
        ->toBe('https://ixora-buckets.tor1.cdn.digitaloceanspaces.com/sounds/1/audio/original.mp3');
});

test('keyFromUrl extracts key from CDN URL', function (): void {
    $svc = new DigitalOceanSpacesService;
    $url = 'https://ixora-buckets.tor1.cdn.digitaloceanspaces.com/sounds/12/thumbnail/thumbnail.webp';

    expect($svc->keyFromUrl($url))->toBe('sounds/12/thumbnail/thumbnail.webp');
});

test('keyFromUrl extracts key from origin virtual-hosted URL', function (): void {
    $svc = new DigitalOceanSpacesService;
    $url = 'https://ixora-buckets.tor1.digitaloceanspaces.com/vibes/3/artwork/artwork.webp';

    expect($svc->keyFromUrl($url))->toBe('vibes/3/artwork/artwork.webp');
});

test('keyFromUrl extracts key from path-style origin URL', function (): void {
    $svc = new DigitalOceanSpacesService;
    $url = 'https://tor1.digitaloceanspaces.com/ixora-buckets/users/9/avatar/avatar.webp';

    expect($svc->keyFromUrl($url))->toBe('users/9/avatar/avatar.webp');
});

test('keyFromUrl returns null for external domain', function (): void {
    $svc = new DigitalOceanSpacesService;

    expect($svc->keyFromUrl('https://evil.example/foo/bar'))->toBeNull();
});

test('keyFromUrl returns null when path is empty', function (): void {
    $svc = new DigitalOceanSpacesService;

    expect($svc->keyFromUrl('https://ixora-buckets.tor1.cdn.digitaloceanspaces.com/'))->toBeNull();
});

test('put stores with normalized key using Storage fake', function (): void {
    Storage::fake('spaces');

    $svc = new DigitalOceanSpacesService;
    $svc->put('/prefix/object.txt', 'hello');

    Storage::disk('spaces')->assertExists('prefix/object.txt');
});

test('putFile stores uploaded file using Storage fake', function (): void {
    Storage::fake('spaces');

    $svc = new DigitalOceanSpacesService;
    $file = UploadedFile::fake()->create('clip.mp3', 10);
    $svc->putFile('/sounds/1/audio/original.mp3', $file);

    Storage::disk('spaces')->assertExists('sounds/1/audio/original.mp3');
});
