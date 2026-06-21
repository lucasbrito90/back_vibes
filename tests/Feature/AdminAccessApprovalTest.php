<?php

declare(strict_types=1);

use App\Mail\AdminAccessRequestedMail;
use App\Models\AdminAccessRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'admin_access.review_email' => 'reviewer@test.com',
        'admin_access.signed_url_ttl_days' => 7,
    ]);
});

function mockJwtForUser(User $user): UnencryptedToken
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

test('POST /api/admin/access-requests creates pending request and queues mail', function () {
    Mail::fake();

    $user = User::factory()->create([
        'firebase_uid' => 'fb-new',
        'admin_access_status' => 'none',
        'role' => 'user',
    ]);

    $jwt = mockJwtForUser($user);
    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn($jwt));

    $this->postJson('/api/admin/access-requests', [], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonStructure(['data' => ['status', 'requested_at']]);

    $user->refresh();
    expect($user->admin_access_status)->toBe('pending');
    expect(AdminAccessRequest::query()->where('user_id', $user->id)->where('status', 'pending')->count())->toBe(1);

    Mail::assertQueued(AdminAccessRequestedMail::class, function (AdminAccessRequestedMail $mail) {
        return $mail->hasTo('reviewer@test.com');
    });
    Mail::assertNothingSent();
});

test('POST /api/admin/access-requests returns pending when request already pending without sending mail', function () {
    Mail::fake();

    $user = User::factory()->create([
        'firebase_uid' => 'fb-pending',
        'admin_access_status' => 'pending',
        'role' => 'user',
    ]);

    AdminAccessRequest::factory()->create([
        'user_id' => $user->id,
        'status' => AdminAccessRequest::STATUS_PENDING,
        'requested_at' => now()->subHour(),
    ]);

    $jwt = mockJwtForUser($user);
    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn($jwt));

    $this->postJson('/api/admin/access-requests', [], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    expect(AdminAccessRequest::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('POST /api/admin/access-requests returns approved for approved admin without queueing mail', function () {
    Mail::fake();

    $user = User::factory()->create([
        'firebase_uid' => 'fb-admin',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $jwt = mockJwtForUser($user);
    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn($jwt));

    $this->postJson('/api/admin/access-requests', [], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

test('signed approve link approves user and request', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'admin_access_status' => 'pending',
    ]);

    $accessRequest = AdminAccessRequest::factory()->create([
        'user_id' => $user->id,
        'status' => AdminAccessRequest::STATUS_PENDING,
    ]);

    $url = URL::temporarySignedRoute(
        'admin.access-requests.approve',
        now()->addDays(7),
        ['adminAccessRequest' => $accessRequest->id],
    );

    $this->get($url)->assertOk()->assertSee('Access approved', false);

    $accessRequest->refresh();
    $user->refresh();

    expect($accessRequest->status)->toBe(AdminAccessRequest::STATUS_APPROVED);
    expect($accessRequest->reviewed_at)->not->toBeNull();
    expect($user->role)->toBe('admin');
    expect($user->admin_access_status)->toBe('approved');
});

test('signed reject link rejects user and request', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'admin_access_status' => 'pending',
    ]);

    $accessRequest = AdminAccessRequest::factory()->create([
        'user_id' => $user->id,
        'status' => AdminAccessRequest::STATUS_PENDING,
    ]);

    $url = URL::temporarySignedRoute(
        'admin.access-requests.reject',
        now()->addDays(7),
        ['adminAccessRequest' => $accessRequest->id],
    );

    $this->get($url)->assertOk()->assertSee('Access rejected', false);

    $accessRequest->refresh();
    $user->refresh();

    expect($accessRequest->status)->toBe(AdminAccessRequest::STATUS_REJECTED);
    expect($user->role)->toBe('user');
    expect($user->admin_access_status)->toBe('rejected');
});

test('non-approved user is blocked by admin approved middleware', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-blocked',
        'role' => 'user',
        'admin_access_status' => 'pending',
    ]);

    $jwt = mockJwtForUser($user);
    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn($jwt));

    $this->getJson('/api/__admin_gate', [
        'Authorization' => 'Bearer tok',
    ])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('approved admin passes admin approved middleware gate', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-ok',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $jwt = mockJwtForUser($user);
    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn($jwt));

    $this->getJson('/api/__admin_gate', [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJsonPath('data.ok', true);
});
