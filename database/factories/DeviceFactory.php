<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\ProviderConnection;
use App\SmartHome\DeviceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 *
 * Creates a ProviderConnection (and its User) when none is provided, then
 * derives user_id and provider from that connection to ensure consistency.
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        $connection = ProviderConnection::factory()->create();

        return [
            'user_id' => $connection->user_id,
            'provider_connection_id' => $connection->id,
            'name' => ucwords(fake()->words(2, asText: true)),
            'type' => fake()->randomElement(['light', 'switch', 'speaker', 'cover', 'fan']),
            'provider' => $connection->provider,
            'provider_device_id' => 'light.'.str_replace([' ', '-'], '_', fake()->words(2, true)),
            'status' => DeviceStatus::Unknown->value,
            'metadata' => null,
            'last_seen_at' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => [
            'status' => DeviceStatus::Online->value,
            'last_seen_at' => now(),
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'status' => DeviceStatus::Offline->value,
            'last_seen_at' => now()->subMinutes(10),
        ]);
    }

    public function unknown(): static
    {
        return $this->state(fn () => [
            'status' => DeviceStatus::Unknown->value,
            'last_seen_at' => null,
        ]);
    }
}
