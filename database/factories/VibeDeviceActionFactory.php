<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\Vibe;
use App\Models\VibeDeviceAction;
use App\SmartHome\ActionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VibeDeviceAction>
 *
 * Creates a Vibe and a Device (each with their own User) when none are provided.
 * Tests that need both to be owned by the same user should create the User, Vibe,
 * and Device explicitly and pass the IDs via ->create([...]).
 */
class VibeDeviceActionFactory extends Factory
{
    protected $model = VibeDeviceAction::class;

    public function definition(): array
    {
        return [
            'vibe_id' => Vibe::factory(),
            'device_id' => Device::factory(),
            'action_type' => ActionType::TurnOn->value,
            'parameters' => null,
            'sort_order' => 0,
            'delay_seconds' => 0,
        ];
    }

    public function turnOn(): static
    {
        return $this->state(fn () => ['action_type' => ActionType::TurnOn->value]);
    }

    public function turnOff(): static
    {
        return $this->state(fn () => ['action_type' => ActionType::TurnOff->value]);
    }

    public function toggle(): static
    {
        return $this->state(fn () => ['action_type' => ActionType::Toggle->value]);
    }
}
