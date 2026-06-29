<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PushToken;
use App\Models\User;
use App\PushNotifications\PushPlatform;
use App\PushNotifications\PushProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushToken>
 */
class PushTokenFactory extends Factory
{
    protected $model = PushToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => 'fcm:'.Str::random(140).':'.Str::random(32),
            'platform' => PushPlatform::Android->value,
            'provider' => PushProvider::Fcm->value,
            'device_id' => fake()->uuid(),
            'app_version' => '0.0.1',
            'device_model' => 'Android Test Device',
            'is_active' => true,
            'last_seen_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
            'revoked_at' => now(),
        ]);
    }

    public function android(): static
    {
        return $this->state(fn () => [
            'platform' => PushPlatform::Android->value,
        ]);
    }

    public function fcm(): static
    {
        return $this->state(fn () => [
            'provider' => PushProvider::Fcm->value,
        ]);
    }
}
