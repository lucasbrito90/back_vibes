<?php

namespace Database\Factories;

use App\Models\Schedule;
use App\Models\ScheduleExecution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleExecution>
 *
 * occurrence_key format: '{schedule_id}:{scheduled_for_unix}' per ADR-010.
 * The afterCreating hook resolves the correct schedule_id after both models exist.
 */
class ScheduleExecutionFactory extends Factory
{
    protected $model = ScheduleExecution::class;

    public function definition(): array
    {
        $scheduledFor = CarbonImmutable::now('UTC')->subMinutes(fake()->numberBetween(1, 60));

        return [
            'schedule_id' => Schedule::factory(),
            'occurrence_key' => '0:'.$scheduledFor->timestamp,
            'scheduled_for' => $scheduledFor,
            'executed_at' => $scheduledFor->addSeconds(fake()->numberBetween(1, 10)),
            'status' => 'dispatched',
            'log' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ScheduleExecution $execution) {
            // Fix occurrence_key to use the real schedule_id after creation.
            $correctKey = "{$execution->schedule_id}:{$execution->scheduled_for->timestamp}";
            if ($execution->occurrence_key !== $correctKey) {
                $execution->updateQuietly(['occurrence_key' => $correctKey]);
            }
        });
    }

    public function dispatched(): static
    {
        return $this->state(fn () => ['status' => 'dispatched']);
    }

    public function forSchedule(Schedule $schedule): static
    {
        $scheduledFor = CarbonImmutable::now('UTC')->subMinutes(fake()->numberBetween(1, 60));

        return $this->state(fn () => [
            'schedule_id' => $schedule->id,
            'occurrence_key' => "{$schedule->id}:{$scheduledFor->timestamp}",
            'scheduled_for' => $scheduledFor,
        ]);
    }
}
