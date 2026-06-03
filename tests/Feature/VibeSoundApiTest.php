<?php

declare(strict_types=1);

use App\Models\Sound;
use App\Models\User;
use App\Models\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function jwtForVibeSoundApiUser(User $user): UnencryptedToken
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

function vibeSoundApiAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(jwtForVibeSoundApiUser($user)));
}

function seedVibeSoundCatalog(string $name): Sound
{
    return Sound::query()->create([
        'name' => $name,
        'file_url' => "https://cdn.example/{$name}.mp3",
        'thumbnail_url' => "https://cdn.example/{$name}.jpg",
        'category' => 'Test',
        'duration' => 90,
    ]);
}

function createOwnedVibe(User $user, string $name = 'Layer Vibe'): Vibe
{
    return Vibe::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'is_active' => true,
    ]);
}

test('user can attach a sound to own vibe', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vs-attach-own']);
    $vibe = createOwnedVibe($user);
    $sound = seedVibeSoundCatalog('Rain');

    vibeSoundApiAuth($user);

    $response = $this->postJson("/api/vibes/{$vibe->id}/sounds", [
        'sound_id' => $sound->id,
        'volume' => 65,
        'sort_order' => 2,
        'play_mode' => 'once',
        'start_offset_seconds' => 5,
        'play_duration_seconds' => 60,
        'fade_in_seconds' => 3,
        'fade_out_seconds' => 4,
    ], [
        'Authorization' => 'Bearer tok',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $sound->id)
        ->assertJsonPath('data.name', 'Rain')
        ->assertJsonPath('data.file_url', 'https://cdn.example/Rain.mp3')
        ->assertJsonPath('data.volume', 65)
        ->assertJsonPath('data.sort_order', 2)
        ->assertJsonPath('data.play_mode', 'once')
        ->assertJsonPath('data.loop', false)
        ->assertJsonPath('data.start_offset_seconds', 5)
        ->assertJsonPath('data.play_duration_seconds', 60)
        ->assertJsonPath('data.fade_in_seconds', 3)
        ->assertJsonPath('data.fade_out_seconds', 4);

    $pivot = $vibe->sounds()->where('sounds.id', $sound->id)->first()->pivot;
    expect((int) $pivot->fade_in_seconds)->toBe(3)
        ->and((int) $pivot->fade_out_seconds)->toBe(4)
        ->and((bool) $pivot->loop)->toBeFalse();
});

test('user cannot attach sound to another users vibe', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-vs-attach-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-vs-attach-bob']);
    $bobVibe = createOwnedVibe($bob);
    $sound = seedVibeSoundCatalog('Wind');

    vibeSoundApiAuth($alice);

    $this->postJson("/api/vibes/{$bobVibe->id}/sounds", [
        'sound_id' => $sound->id,
    ], [
        'Authorization' => 'Bearer tok',
    ])->assertForbidden();

    expect($bobVibe->sounds()->count())->toBe(0);
});

test('user can list sounds attached to own vibe in sort_order', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vs-list-own']);
    $vibe = createOwnedVibe($user);
    $s1 = seedVibeSoundCatalog('A');
    $s2 = seedVibeSoundCatalog('B');
    $s3 = seedVibeSoundCatalog('C');

    $vibe->sounds()->attach($s1->id, ['volume' => 80, 'loop' => true, 'sort_order' => 2]);
    $vibe->sounds()->attach($s2->id, ['volume' => 80, 'loop' => true, 'sort_order' => 0]);
    $vibe->sounds()->attach($s3->id, ['volume' => 80, 'loop' => true, 'sort_order' => 1]);

    vibeSoundApiAuth($user);

    $response = $this->getJson("/api/vibes/{$vibe->id}/sounds", [
        'Authorization' => 'Bearer tok',
    ]);

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toBe(['B', 'C', 'A']);
});

test('user cannot list sounds from another users vibe', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-vs-list-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-vs-list-bob']);
    $bobVibe = createOwnedVibe($bob);
    $sound = seedVibeSoundCatalog('Secret');
    $bobVibe->sounds()->attach($sound->id, ['volume' => 80, 'loop' => true, 'sort_order' => 0]);

    vibeSoundApiAuth($alice);

    $this->getJson("/api/vibes/{$bobVibe->id}/sounds", [
        'Authorization' => 'Bearer tok',
    ])->assertForbidden();
});

test('user can update own vibe_sound pivot fields', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vs-update-own']);
    $vibe = createOwnedVibe($user);
    $sound = seedVibeSoundCatalog('Ocean');

    $vibe->sounds()->attach($sound->id, [
        'volume' => 50,
        'loop' => true,
        'play_mode' => 'loop',
        'sort_order' => 0,
        'repeat_interval_seconds' => null,
    ]);

    vibeSoundApiAuth($user);

    $this->patchJson("/api/vibes/{$vibe->id}/sounds/{$sound->id}", [
        'volume' => 90,
        'sort_order' => 5,
        'play_mode' => 'interval',
        'repeat_interval_seconds' => 180,
        'start_offset_seconds' => 10,
        'play_duration_seconds' => 45,
        'fade_in_seconds' => 2,
        'fade_out_seconds' => 6,
    ], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJsonPath('data.volume', 90)
        ->assertJsonPath('data.sort_order', 5)
        ->assertJsonPath('data.play_mode', 'interval')
        ->assertJsonPath('data.loop', false)
        ->assertJsonPath('data.repeat_interval_seconds', 180)
        ->assertJsonPath('data.start_offset_seconds', 10)
        ->assertJsonPath('data.play_duration_seconds', 45)
        ->assertJsonPath('data.fade_in_seconds', 2)
        ->assertJsonPath('data.fade_out_seconds', 6);

    $pivot = $vibe->sounds()->where('sounds.id', $sound->id)->first()->pivot;
    expect((int) $pivot->repeat_interval_seconds)->toBe(180)
        ->and($pivot->play_mode)->toBe('interval')
        ->and((bool) $pivot->loop)->toBeFalse();
});

test('user cannot update another users vibe_sound', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-vs-update-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-vs-update-bob']);
    $bobVibe = createOwnedVibe($bob);
    $sound = seedVibeSoundCatalog('Locked');
    $bobVibe->sounds()->attach($sound->id, ['volume' => 40, 'loop' => true, 'sort_order' => 0]);

    vibeSoundApiAuth($alice);

    $this->patchJson("/api/vibes/{$bobVibe->id}/sounds/{$sound->id}", [
        'volume' => 99,
    ], [
        'Authorization' => 'Bearer tok',
    ])->assertForbidden();

    expect((int) $bobVibe->sounds()->first()->pivot->volume)->toBe(40);
});

test('user can detach sound from own vibe', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vs-detach-own']);
    $vibe = createOwnedVibe($user);
    $sound = seedVibeSoundCatalog('DetachMe');
    $vibe->sounds()->attach($sound->id, ['volume' => 80, 'loop' => true, 'sort_order' => 0]);

    vibeSoundApiAuth($user);

    $this->deleteJson("/api/vibes/{$vibe->id}/sounds/{$sound->id}", [], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJson(['message' => 'Sound removed from vibe.']);

    expect($vibe->sounds()->count())->toBe(0);
});

test('user cannot detach sound from another users vibe', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-vs-detach-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-vs-detach-bob']);
    $bobVibe = createOwnedVibe($bob);
    $sound = seedVibeSoundCatalog('Stay');
    $bobVibe->sounds()->attach($sound->id, ['volume' => 80, 'loop' => true, 'sort_order' => 0]);

    vibeSoundApiAuth($alice);

    $this->deleteJson("/api/vibes/{$bobVibe->id}/sounds/{$sound->id}", [], [
        'Authorization' => 'Bearer tok',
    ])->assertForbidden();

    expect($bobVibe->sounds()->count())->toBe(1);
});

test('validation errors return 422 for invalid attach payload', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vs-422-attach']);
    $vibe = createOwnedVibe($user);

    vibeSoundApiAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/sounds", [], [
        'Authorization' => 'Bearer tok',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['sound_id']);

    $this->postJson("/api/vibes/{$vibe->id}/sounds", [
        'sound_id' => 99999,
    ], [
        'Authorization' => 'Bearer tok',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['sound_id']);

    $this->postJson("/api/vibes/{$vibe->id}/sounds", [
        'sound_id' => seedVibeSoundCatalog('X')->id,
        'play_mode' => 'interval',
    ], [
        'Authorization' => 'Bearer tok',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['repeat_interval_seconds']);
});

test('validation errors return 422 for invalid update payload', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vs-422-update']);
    $vibe = createOwnedVibe($user);
    $sound = seedVibeSoundCatalog('Y');
    $vibe->sounds()->attach($sound->id, ['volume' => 80, 'loop' => true, 'sort_order' => 0]);

    vibeSoundApiAuth($user);

    $this->patchJson("/api/vibes/{$vibe->id}/sounds/{$sound->id}", [
        'volume' => 150,
    ], [
        'Authorization' => 'Bearer tok',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['volume']);
});

test('attach derives loop from play_mode and ignores client loop flag', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vs-loop-derive']);
    $vibe = createOwnedVibe($user);
    $sound = seedVibeSoundCatalog('LoopTest');

    vibeSoundApiAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/sounds", [
        'sound_id' => $sound->id,
        'play_mode' => 'loop',
        'loop' => false,
    ], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJsonPath('data.loop', true);

    $pivot = $vibe->sounds()->first()->pivot;
    expect((bool) $pivot->loop)->toBeTrue();
});

test('attach with interval mode stores repeat_interval_seconds and clears it when play_mode changes to loop', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vs-interval']);
    $vibe = createOwnedVibe($user);
    $sound = seedVibeSoundCatalog('Interval');

    vibeSoundApiAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/sounds", [
        'sound_id' => $sound->id,
        'play_mode' => 'interval',
        'repeat_interval_seconds' => 90,
    ], [
        'Authorization' => 'Bearer tok',
    ])->assertOk()
        ->assertJsonPath('data.repeat_interval_seconds', 90);

    $this->patchJson("/api/vibes/{$vibe->id}/sounds/{$sound->id}", [
        'play_mode' => 'loop',
    ], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJsonPath('data.play_mode', 'loop')
        ->assertJsonPath('data.loop', true)
        ->assertJsonPath('data.repeat_interval_seconds', null);

    $pivot = $vibe->sounds()->first()->pivot;
    expect($pivot->repeat_interval_seconds)->toBeNull()
        ->and((bool) $pivot->loop)->toBeTrue();
});

test('fade fields are persisted on attach without implying server-side fade execution', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vs-fade']);
    $vibe = createOwnedVibe($user);
    $sound = seedVibeSoundCatalog('Fade');

    vibeSoundApiAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/sounds", [
        'sound_id' => $sound->id,
        'fade_in_seconds' => 8,
        'fade_out_seconds' => 12,
    ], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJsonPath('data.fade_in_seconds', 8)
        ->assertJsonPath('data.fade_out_seconds', 12);

    $pivot = $vibe->sounds()->first()->pivot;
    expect((int) $pivot->fade_in_seconds)->toBe(8)
        ->and((int) $pivot->fade_out_seconds)->toBe(12);
});

test('listing order remains deterministic after API attach sequence', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vs-order']);
    $vibe = createOwnedVibe($user);
    $s1 = seedVibeSoundCatalog('First');
    $s2 = seedVibeSoundCatalog('Second');

    vibeSoundApiAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/sounds", [
        'sound_id' => $s1->id,
        'sort_order' => 1,
    ], ['Authorization' => 'Bearer tok'])->assertOk();

    $this->postJson("/api/vibes/{$vibe->id}/sounds", [
        'sound_id' => $s2->id,
        'sort_order' => 0,
    ], ['Authorization' => 'Bearer tok'])->assertOk();

    $names = collect(
        $this->getJson("/api/vibes/{$vibe->id}/sounds", ['Authorization' => 'Bearer tok'])
            ->assertOk()
            ->json('data')
    )->pluck('name')->all();

    expect($names)->toBe(['Second', 'First']);
});
