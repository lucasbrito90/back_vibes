<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vibe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vibe>
 */
class VibeFactory extends Factory
{
    protected $model = Vibe::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, asText: true),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
