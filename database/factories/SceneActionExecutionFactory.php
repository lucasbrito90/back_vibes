<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\SceneActionExecution;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SceneActionExecution>
 */
class SceneActionExecutionFactory extends Factory
{
    protected $model = SceneActionExecution::class;

    public function definition(): array
    {
        $connection = ProviderConnection::factory()->create();
        $scene = Scene::factory()->create(['user_id' => $connection->user_id]);
        $device = Device::factory()->create([
            'user_id' => $connection->user_id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
        ]);
        $action = SceneAction::factory()->create([
            'scene_id' => $scene->id,
            'device_id' => $device->id,
        ]);
        $executedAt = now();

        return [
            'scene_execution_id' => (string) Str::uuid(),
            'scene_id' => $scene->id,
            'scene_action_id' => $action->id,
            'device_id' => $device->id,
            'provider' => $connection->provider,
            'provider_connection_id' => $connection->id,
            'action_type' => $action->action_type,
            'outcome' => SmartHomeActionOutcome::Success->value,
            'failure_category' => null,
            'http_status_code' => 200,
            'duration_ms' => fake()->numberBetween(10, 500),
            'trace_id' => null,
            'attempt' => 1,
            'executed_at' => $executedAt,
            'created_at' => $executedAt,
        ];
    }

    public function outcome(SmartHomeActionOutcome $outcome): static
    {
        return $this->state(fn () => [
            'outcome' => $outcome->value,
            'failure_category' => match ($outcome) {
                SmartHomeActionOutcome::Success => null,
                SmartHomeActionOutcome::Unsupported => 'unsupported_action',
                SmartHomeActionOutcome::Unknown => 'unexpected',
                SmartHomeActionOutcome::Failure => 'provider_error',
            },
        ]);
    }

    public function olderThanDays(int $days): static
    {
        $createdAt = now()->subDays($days);

        return $this->state(fn () => [
            'executed_at' => $createdAt,
            'created_at' => $createdAt,
        ]);
    }
}
