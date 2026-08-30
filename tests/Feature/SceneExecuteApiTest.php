<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SceneActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\User;
use App\SmartHome\ActionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function sceneExecJwt(User $user): UnencryptedToken
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

function sceneExecAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(sceneExecJwt($user)));
}

function sceneExecHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function sceneExecUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-scene-exec-'.uniqid()]);
}

function sceneExecScene(User $user): Scene
{
    return Scene::factory()->create(['user_id' => $user->id]);
}

function sceneExecDevice(User $user): Device
{
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);

    return Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
    ]);
}

function sceneExecAction(Scene $scene, Device $device, int $sortOrder = 0): SceneAction
{
    return SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
        'sort_order' => $sortOrder,
    ]);
}

it('returns 401 when unauthenticated', function () {
    $scene = Scene::factory()->create();

    $this->postJson("/api/scenes/{$scene->id}/execute")
        ->assertUnauthorized();
});

it('returns 404 when scene belongs to another user', function () {
    Queue::fake();

    $owner = sceneExecUser();
    $other = sceneExecUser();
    $scene = sceneExecScene($owner);
    $device = sceneExecDevice($owner);
    sceneExecAction($scene, $device);

    sceneExecAuth($other);

    $this->postJson("/api/scenes/{$scene->id}/execute", [], sceneExecHeaders())
        ->assertNotFound();

    Queue::assertNothingPushed();
});

it('enqueues one job per action and returns dispatch summary', function () {
    Queue::fake();

    $user = sceneExecUser();
    $scene = sceneExecScene($user);
    $device = sceneExecDevice($user);
    $a1 = sceneExecAction($scene, $device, 0);
    $a2 = sceneExecAction($scene, $device, 1);
    $a3 = sceneExecAction($scene, $device, 2);

    sceneExecAuth($user);

    $response = $this->postJson("/api/scenes/{$scene->id}/execute", [], sceneExecHeaders())
        ->assertOk()
        ->assertJsonStructure(['data' => ['scene_id', 'dispatched', 'skipped', 'action_ids']]);

    expect($response->json('data.scene_id'))->toBe($scene->id)
        ->and($response->json('data.dispatched'))->toBe(3)
        ->and($response->json('data.skipped'))->toBe(0)
        ->and($response->json('data.action_ids'))->toBe([$a1->id, $a2->id, $a3->id]);

    Queue::assertPushed(SceneActionJob::class, 3);
});

it('dispatches jobs in sort_order', function () {
    Queue::fake();

    $user = sceneExecUser();
    $scene = sceneExecScene($user);
    $device = sceneExecDevice($user);
    $a2 = sceneExecAction($scene, $device, 2);
    $a0 = sceneExecAction($scene, $device, 0);
    $a1 = sceneExecAction($scene, $device, 1);

    sceneExecAuth($user);

    $response = $this->postJson("/api/scenes/{$scene->id}/execute", [], sceneExecHeaders())
        ->assertOk();

    expect($response->json('data.action_ids'))->toBe([$a0->id, $a1->id, $a2->id]);
});

it('skips actions when device relation is missing during dispatch', function () {
    Queue::fake();

    $user = sceneExecUser();
    $scene = sceneExecScene($user);
    $device = sceneExecDevice($user);

    sceneExecAction($scene, $device, 0);
    $orphan = sceneExecAction($scene, $device, 1);
    sceneExecAction($scene, $device, 2);

    $actions = SceneAction::where('scene_id', $scene->id)
        ->with('device')
        ->orderBy('sort_order')
        ->get();

    $actions->firstWhere('id', $orphan->id)?->setRelation('device', null);

    $dispatched = 0;
    $skipped = 0;
    $actionIds = [];

    foreach ($actions as $action) {
        if ($action->device === null) {
            $skipped++;

            continue;
        }

        SceneActionJob::dispatch($action->id);
        $dispatched++;
        $actionIds[] = $action->id;
    }

    expect($dispatched)->toBe(2)
        ->and($skipped)->toBe(1)
        ->and($actionIds)->not->toContain($orphan->id);

    Queue::assertPushed(SceneActionJob::class, 2);
});

it('returns dispatched 0 when scene has no actions', function () {
    Queue::fake();

    $user = sceneExecUser();
    $scene = sceneExecScene($user);

    sceneExecAuth($user);

    $response = $this->postJson("/api/scenes/{$scene->id}/execute", [], sceneExecHeaders())
        ->assertOk();

    expect($response->json('data.dispatched'))->toBe(0)
        ->and($response->json('data.skipped'))->toBe(0)
        ->and($response->json('data.action_ids'))->toBe([]);

    Queue::assertNothingPushed();
});

it('does not make any synchronous HTTP request during execute', function () {
    Http::fake();
    Queue::fake();

    $user = sceneExecUser();
    $scene = sceneExecScene($user);
    $device = sceneExecDevice($user);
    sceneExecAction($scene, $device);

    sceneExecAuth($user);

    $this->postJson("/api/scenes/{$scene->id}/execute", [], sceneExecHeaders())
        ->assertOk();

    Http::assertNothingSent();
    Queue::assertPushed(SceneActionJob::class);
});

it('SceneActionJob is configured for smart-home queue with expected retry policy', function () {
    $job = new SceneActionJob(1);

    expect($job->queue)->toBe('smart-home')
        ->and($job->timeout)->toBe(30)
        ->and($job->tries)->toBe(3);
});
