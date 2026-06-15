<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\ConnectionStatus;
use App\SmartHome\ProviderType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<ProviderConnection>
 */
class ProviderConnectionFactory extends Factory
{
    protected $model = ProviderConnection::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Home Assistant',
            'provider' => ProviderType::HomeAssistant->value,
            'config' => ['base_url' => 'https://ha.example.test'],
            'encrypted_credentials' => Crypt::encryptString(json_encode(['access_token' => 'test-token-'.fake()->uuid()])),
            'status' => ConnectionStatus::Unknown->value,
            'last_tested_at' => null,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn () => [
            'status' => ConnectionStatus::Connected->value,
            'last_tested_at' => now(),
        ]);
    }

    public function unreachable(): static
    {
        return $this->state(fn () => [
            'status' => ConnectionStatus::Unreachable->value,
            'last_tested_at' => now()->subMinutes(5),
        ]);
    }
}
