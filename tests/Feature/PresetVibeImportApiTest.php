<?php

declare(strict_types=1);

use App\Models\CoverBundle;
use App\Models\PresetVibe;
use App\Models\PresetVibeSound;
use App\Models\Sound;
use App\Models\User;
use App\Models\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function jwtForPresetImportUser(User $user): UnencryptedToken
{
    $dataset = new DataSet([
        'sub' => $user->firebase_uid,
        'email' => $user->email,
        'name' => $user->name,
    ], 'e30.');
    $jwt = Mockery::mock(UnencryptedToken::class);
    $jwt->shouldReceive('claims')->andReturn($dataset);

    return $jwt;
}

function seedSound(string $name): Sound
{
    return Sound::query()->create([
        'name' => $name,
        'file_url' => "https://cdn.example/{$name}.mp3",
        'category' => 'Test',
        'duration' => 120,
    ]);
}

test('authenticated user imports active preset with cover bundle and layers', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-pvi-1']);

    $bundle = CoverBundle::query()->create([
        'name' => 'Visual Pack',
        'thumbnail_url' => 'https://cdn.example/th.jpg',
        'artwork_url' => 'https://cdn.example/ar.jpg',
        'player_background_url' => 'https://cdn.example/bg.jpg',
        'is_active' => true,
    ]);

    $s1 = seedSound('Rain');
    $s2 = seedSound('Wind');

    $preset = PresetVibe::query()->create([
        'name' => 'Storm Kit',
        'description' => 'Layered storm',
        'cover_bundle_id' => $bundle->id,
        'category' => 'Weather',
        'tags' => ['storm'],
        'is_active' => true,
    ]);

    PresetVibeSound::query()->create([
        'preset_vibe_id' => $preset->id,
        'sound_id' => $s1->id,
        'volume' => 77,
        'loop' => false,
        'play_mode' => 'once',
        'sort_order' => 1,
        'repeat_interval_seconds' => null,
        'start_offset_seconds' => 3,
        'play_duration_seconds' => 90,
    ]);

    PresetVibeSound::query()->create([
        'preset_vibe_id' => $preset->id,
        'sound_id' => $s2->id,
        'volume' => 55,
        'loop' => true,
        'play_mode' => 'interval',
        'sort_order' => 0,
        'repeat_interval_seconds' => 120,
        'start_offset_seconds' => null,
        'play_duration_seconds' => null,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetImportUser($user)));

    $response = $this->postJson("/api/preset-vibes/{$preset->id}/import", [], [
        'Authorization' => 'Bearer tok',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Storm Kit')
        ->assertJsonPath('data.description', 'Layered storm')
        ->assertJsonPath('data.thumbnail_url', 'https://cdn.example/th.jpg')
        ->assertJsonPath('data.artwork_url', 'https://cdn.example/ar.jpg')
        ->assertJsonPath('data.player_background_url', 'https://cdn.example/bg.jpg')
        ->assertJsonPath('data.sounds_count', 2);

    $vibeId = (int) $response->json('data.id');

    $vibe = Vibe::query()->findOrFail($vibeId);
    expect($vibe->user_id)->toBe($user->id)
        ->and($vibe->thumbnail_url)->toBe('https://cdn.example/th.jpg')
        ->and($vibe->artwork_url)->toBe('https://cdn.example/ar.jpg')
        ->and($vibe->player_background_url)->toBe('https://cdn.example/bg.jpg');

    $rows = $vibe->sounds()->orderByPivot('sort_order')->get();
    expect($rows)->toHaveCount(2);

    $first = $rows->firstWhere('id', $s2->id);
    expect((int) $first->pivot->sort_order)->toBe(0)
        ->and($first->pivot->play_mode)->toBe('interval')
        ->and((int) $first->pivot->repeat_interval_seconds)->toBe(120);

    $second = $rows->firstWhere('id', $s1->id);
    expect($second->pivot->play_mode)->toBe('once')
        ->and((bool) $second->pivot->loop)->toBeFalse()
        ->and((int) $second->pivot->volume)->toBe(77)
        ->and((int) $second->pivot->start_offset_seconds)->toBe(3)
        ->and((int) $second->pivot->play_duration_seconds)->toBe(90);
});

test('inactive preset cannot be imported', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-pvi-off']);

    $preset = PresetVibe::query()->create([
        'name' => 'Draft',
        'is_active' => false,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetImportUser($user)));

    $this->postJson("/api/preset-vibes/{$preset->id}/import", [], [
        'Authorization' => 'Bearer tok',
    ])->assertNotFound();

    expect(Vibe::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('importing active preset without cover leaves image fields null', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-pvi-nocover']);

    $preset = PresetVibe::query()->create([
        'name' => 'Bare',
        'description' => null,
        'cover_bundle_id' => null,
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetImportUser($user)));

    $this->postJson("/api/preset-vibes/{$preset->id}/import", [], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Bare');

    $vibe = Vibe::query()->where('user_id', $user->id)->firstOrFail();
    expect($vibe->thumbnail_url)->toBeNull()
        ->and($vibe->artwork_url)->toBeNull()
        ->and($vibe->player_background_url)->toBeNull();
});

test('importing copies inactive cover bundle urls when preset references it', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-pvi-inactive-bundle']);

    $bundle = CoverBundle::query()->create([
        'name' => 'Inactive Pack',
        'thumbnail_url' => 'https://cdn.example/inactive-t.jpg',
        'artwork_url' => 'https://cdn.example/inactive-a.jpg',
        'player_background_url' => 'https://cdn.example/inactive-p.jpg',
        'is_active' => false,
    ]);

    $preset = PresetVibe::query()->create([
        'name' => 'Uses inactive bundle',
        'cover_bundle_id' => $bundle->id,
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetImportUser($user)));

    $this->postJson("/api/preset-vibes/{$preset->id}/import", [], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertCreated()
        ->assertJsonPath('data.thumbnail_url', 'https://cdn.example/inactive-t.jpg');
});

test('importing preset twice creates two independent vibes for same user', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-pvi-double']);

    $preset = PresetVibe::query()->create([
        'name' => 'Duplicate Me',
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->times(2)->andReturn(jwtForPresetImportUser($user)));

    $this->postJson("/api/preset-vibes/{$preset->id}/import", [], [
        'Authorization' => 'Bearer tok',
    ])->assertCreated();

    $this->postJson("/api/preset-vibes/{$preset->id}/import", [], [
        'Authorization' => 'Bearer tok',
    ])->assertCreated();

    expect(Vibe::query()->where('user_id', $user->id)->where('name', 'Duplicate Me')->count())->toBe(2);
});

test('import does not create vibes for other users', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-pvi-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-pvi-bob']);

    Vibe::query()->create([
        'user_id' => $bob->id,
        'name' => 'Bob original',
        'is_active' => true,
    ]);

    $preset = PresetVibe::query()->create([
        'name' => 'Shared Template',
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetImportUser($alice)));

    $this->postJson("/api/preset-vibes/{$preset->id}/import", [], [
        'Authorization' => 'Bearer tok',
    ])->assertCreated();

    expect(Vibe::query()->where('user_id', $alice->id)->count())->toBe(1)
        ->and(Vibe::query()->where('user_id', $bob->id)->count())->toBe(1);
});

test('preset without sounds imports empty vibe layers', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-pvi-empty']);

    $preset = PresetVibe::query()->create([
        'name' => 'Silent',
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetImportUser($user)));

    $response = $this->postJson("/api/preset-vibes/{$preset->id}/import", [], [
        'Authorization' => 'Bearer tok',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.sounds_count', 0);

    $id = (int) $response->json('data.id');
    expect(Vibe::query()->find($id)->sounds()->count())->toBe(0);
});
