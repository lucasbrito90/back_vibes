<?php

declare(strict_types=1);

use App\Models\Schedule;
use App\Models\User;
use App\Models\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function vibeEnrichJwt(User $user): UnencryptedToken
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

function vibeEnrichAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(vibeEnrichJwt($user)));
}

function vibeEnrichHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function makeVibeUser(string $uid): User
{
    return User::factory()->create(['firebase_uid' => $uid]);
}

// ─────────────────────────────────────────────────────────────────────────────
// active_schedules_count — 0 / 1 / multiple
// ─────────────────────────────────────────────────────────────────────────────

test('active_schedules_count is 0 when vibe has no schedules', function () {
    $user = makeVibeUser('fb-ve-asc-zero');
    $vibe = Vibe::factory()->for($user)->create();
    vibeEnrichAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.active_schedules_count', 0);
});

test('active_schedules_count is 1 when vibe has one enabled schedule', function () {
    $user = makeVibeUser('fb-ve-asc-one');
    $vibe = Vibe::factory()->for($user)->create();
    Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['is_enabled' => true]);
    vibeEnrichAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.active_schedules_count', 1);
});

test('active_schedules_count is correct when vibe has multiple enabled schedules', function () {
    $user = makeVibeUser('fb-ve-asc-multi');
    $vibe = Vibe::factory()->for($user)->create();
    Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['is_enabled' => true]);
    Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['is_enabled' => true]);
    Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['is_enabled' => true]);
    vibeEnrichAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.active_schedules_count', 3);
});

// ─────────────────────────────────────────────────────────────────────────────
// Disabled schedules do NOT count
// ─────────────────────────────────────────────────────────────────────────────

test('disabled schedules are excluded from active_schedules_count', function () {
    $user = makeVibeUser('fb-ve-asc-disabled');
    $vibe = Vibe::factory()->for($user)->create();

    Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['is_enabled' => true]);
    Schedule::factory()->disabled()->for($user, 'user')->for($vibe, 'vibe')->create();
    Schedule::factory()->disabled()->for($user, 'user')->for($vibe, 'vibe')->create();

    vibeEnrichAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.active_schedules_count', 1);
});

test('active_schedules_count is 0 when all schedules are disabled', function () {
    $user = makeVibeUser('fb-ve-asc-all-disabled');
    $vibe = Vibe::factory()->for($user)->create();

    Schedule::factory()->disabled()->for($user, 'user')->for($vibe, 'vibe')->create();
    Schedule::factory()->disabled()->for($user, 'user')->for($vibe, 'vibe')->create();

    vibeEnrichAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.active_schedules_count', 0);
});

// ─────────────────────────────────────────────────────────────────────────────
// has_active_schedule — true / false
// ─────────────────────────────────────────────────────────────────────────────

test('has_active_schedule is false when vibe has no schedules', function () {
    $user = makeVibeUser('fb-ve-has-false-none');
    $vibe = Vibe::factory()->for($user)->create();
    vibeEnrichAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.has_active_schedule', false);
});

test('has_active_schedule is false when all schedules are disabled', function () {
    $user = makeVibeUser('fb-ve-has-false-disabled');
    $vibe = Vibe::factory()->for($user)->create();
    Schedule::factory()->disabled()->for($user, 'user')->for($vibe, 'vibe')->create();
    vibeEnrichAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.has_active_schedule', false);
});

test('has_active_schedule is true when vibe has at least one enabled schedule', function () {
    $user = makeVibeUser('fb-ve-has-true');
    $vibe = Vibe::factory()->for($user)->create();
    Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['is_enabled' => true]);
    vibeEnrichAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.has_active_schedule', true);
});

test('has_active_schedule is true when vibe has mixed enabled and disabled schedules', function () {
    $user = makeVibeUser('fb-ve-has-mixed');
    $vibe = Vibe::factory()->for($user)->create();
    Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['is_enabled' => true]);
    Schedule::factory()->disabled()->for($user, 'user')->for($vibe, 'vibe')->create();
    vibeEnrichAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.has_active_schedule', true);
});

// ─────────────────────────────────────────────────────────────────────────────
// Fields returned on index as well as show
// ─────────────────────────────────────────────────────────────────────────────

test('active_schedules_count and has_active_schedule appear on index', function () {
    $user = makeVibeUser('fb-ve-asc-index');
    $vibe = Vibe::factory()->for($user)->create();
    Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['is_enabled' => true]);
    Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['is_enabled' => true]);
    vibeEnrichAuth($user);

    $response = $this->getJson('/api/vibes', vibeEnrichHeaders())->assertOk();

    expect($response->json('data.0.active_schedules_count'))->toBe(2)
        ->and($response->json('data.0.has_active_schedule'))->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// active_schedules_count counts only schedules for the current vibe
// ─────────────────────────────────────────────────────────────────────────────

test('active_schedules_count is scoped to the vibe and not affected by other vibes schedules', function () {
    $user = makeVibeUser('fb-ve-asc-isolation');

    $vibeA = Vibe::factory()->for($user)->create();
    $vibeB = Vibe::factory()->for($user)->create();

    Schedule::factory()->daily()->for($user, 'user')->for($vibeA, 'vibe')->create(['is_enabled' => true]);
    Schedule::factory()->daily()->for($user, 'user')->for($vibeB, 'vibe')->create(['is_enabled' => true]);
    Schedule::factory()->daily()->for($user, 'user')->for($vibeB, 'vibe')->create(['is_enabled' => true]);
    Schedule::factory()->daily()->for($user, 'user')->for($vibeB, 'vibe')->create(['is_enabled' => true]);

    vibeEnrichAuth($user);

    $this->getJson("/api/vibes/{$vibeA->id}", vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.active_schedules_count', 1)
        ->assertJsonPath('data.has_active_schedule', true);
});

// ─────────────────────────────────────────────────────────────────────────────
// Fields appear after store
// ─────────────────────────────────────────────────────────────────────────────

test('active_schedules_count and has_active_schedule are present after store', function () {
    $user = makeVibeUser('fb-ve-asc-store');
    vibeEnrichAuth($user);

    $this->postJson('/api/vibes', [
        'name' => 'Brand New Vibe',
        'is_active' => true,
    ], vibeEnrichHeaders())
        ->assertCreated()
        ->assertJsonPath('data.active_schedules_count', 0)
        ->assertJsonPath('data.has_active_schedule', false);
});

// ─────────────────────────────────────────────────────────────────────────────
// Fields appear after update
// ─────────────────────────────────────────────────────────────────────────────

test('active_schedules_count and has_active_schedule are present after update', function () {
    $user = makeVibeUser('fb-ve-asc-update');
    $vibe = Vibe::factory()->for($user)->create(['name' => 'Before']);
    Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['is_enabled' => true]);
    vibeEnrichAuth($user);

    $this->patchJson("/api/vibes/{$vibe->id}", ['name' => 'After'], vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.active_schedules_count', 1)
        ->assertJsonPath('data.has_active_schedule', true);
});

// ─────────────────────────────────────────────────────────────────────────────
// Full resource shape includes new fields
// ─────────────────────────────────────────────────────────────────────────────

test('VibeResource includes active_schedules_count and has_active_schedule in structure', function () {
    $user = makeVibeUser('fb-ve-structure');
    $vibe = Vibe::factory()->for($user)->create();
    vibeEnrichAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", vibeEnrichHeaders())
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'description',
                'thumbnail_url',
                'card_image_url',
                'player_background_url',
                'artwork_url',
                'is_active',
                'sounds_count',
                'active_schedules_count',
                'has_active_schedule',
                'created_at',
                'updated_at',
            ],
        ]);
});
