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
        $type = fake()->randomElement(['light', 'switch', 'speaker', 'cover', 'fan']);

        return [
            'user_id' => $connection->user_id,
            'provider_connection_id' => $connection->id,
            'name' => ucwords(fake()->words(2, asText: true)),
            'type' => $type,
            'provider' => $connection->provider,
            'provider_device_id' => 'light.'.str_replace([' ', '-'], '_', fake()->words(2, true)),
            'status' => DeviceStatus::Unknown->value,
            'metadata' => null,
            'capabilities' => self::adr033CapabilitiesForType($type),
            'last_seen_at' => null,
        ];
    }

    /**
     * ADR-033 map format for test fixtures (not derived from metadata — T16 owns derivation).
     *
     * @return array<string, array<string, mixed>>|null
     */
    public static function adr033CapabilitiesForType(string $type): ?array
    {
        $booleanCapabilities = [
            'can_turn_on' => [],
            'can_turn_off' => [],
            'can_toggle' => [],
        ];

        return match ($type) {
            'light' => array_merge($booleanCapabilities, [
                'can_set_brightness' => ['min' => 0, 'max' => 255, 'step' => 1],
            ]),
            'switch', 'fan', 'cover', 'speaker' => $booleanCapabilities,
            default => null,
        };
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

    /** Device with null capabilities (ADR-033 unknown / fail-open). */
    public function withoutCapabilities(): static
    {
        return $this->state(fn () => ['capabilities' => null]);
    }

    /** Dimmable light fixture with full ADR-033 brightness constraints. */
    public function dimmableLight(): static
    {
        return $this->state(fn () => [
            'type' => 'light',
            'capabilities' => self::adr033CapabilitiesForType('light'),
        ]);
    }
}
