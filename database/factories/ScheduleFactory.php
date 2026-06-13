<?php

namespace Database\Factories;

use App\Models\Schedule;
use App\Models\User;
use App\Models\Vibe;
use App\Services\Scheduling\RecurrenceType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 *
 * MVP recurrence types: once, daily, weekdays, weekly.
 * monthly is reserved and excluded from factory defaults.
 */
class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    /** MVP-supported recurrence types (monthly excluded per spec). */
    private const MVP_TYPES = [
        RecurrenceType::Once,
        RecurrenceType::Daily,
        RecurrenceType::Weekdays,
        RecurrenceType::Weekly,
    ];

    public function definition(): array
    {
        $type = fake()->randomElement(self::MVP_TYPES);
        $startTime = CarbonImmutable::now('UTC')->addHour();

        return [
            'user_id' => User::factory(),
            'vibe_id' => Vibe::factory(),
            'name' => fake()->words(3, asText: true),
            'timezone' => fake()->randomElement(['UTC', 'America/Sao_Paulo', 'Europe/London', 'America/New_York']),
            'start_time' => $startTime,
            'recurrence_type' => $type->value,
            'recurrence_config' => $this->configFor($type),
            'is_enabled' => true,
            'next_run_at' => $startTime,
            'last_run_at' => null,
        ];
    }

    public function once(): static
    {
        return $this->state(fn () => [
            'recurrence_type' => RecurrenceType::Once->value,
            'recurrence_config' => null,
        ]);
    }

    public function daily(): static
    {
        return $this->state(fn () => [
            'recurrence_type' => RecurrenceType::Daily->value,
            'recurrence_config' => null,
        ]);
    }

    public function weekdays(): static
    {
        return $this->state(fn () => [
            'recurrence_type' => RecurrenceType::Weekdays->value,
            'recurrence_config' => null,
        ]);
    }

    public function weekly(array $daysOfWeek = [1, 3, 5]): static
    {
        return $this->state(fn () => [
            'recurrence_type' => RecurrenceType::Weekly->value,
            'recurrence_config' => ['days_of_week' => $daysOfWeek],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'is_enabled' => false,
            'next_run_at' => null,
        ]);
    }

    public function due(): static
    {
        return $this->state(fn () => [
            'is_enabled' => true,
            'next_run_at' => CarbonImmutable::now('UTC')->subMinute(),
        ]);
    }

    private function configFor(RecurrenceType $type): ?array
    {
        if ($type === RecurrenceType::Weekly) {
            $days = fake()->randomElements([1, 2, 3, 4, 5, 6, 7], fake()->numberBetween(1, 3));
            sort($days);

            return ['days_of_week' => $days];
        }

        return null;
    }
}
