<?php

declare(strict_types=1);

namespace App\SmartHome\Validation;

use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\Schedule;
use App\Models\Vibe;
use Illuminate\Database\Eloquent\Collection;

/**
 * Domain validator for schedule-triggered Smart Home automation execution.
 *
 * Validates ownership and entity integrity without HTTP auth (ADR-026).
 * Returns false for expected failures — never throws.
 *
 * Caller is responsible for logging when validation returns false.
 */
final class ScheduleAutomationValidator
{
    public function validate(Schedule $schedule): bool
    {
        $vibe = $this->resolveVibe($schedule);

        if ($vibe === null) {
            return false;
        }

        if ($schedule->user_id !== $vibe->user_id) {
            return false;
        }

        $actions = $this->resolveDeviceActions($vibe);

        foreach ($actions as $action) {
            if (! $this->isActionValidForSchedule($action, $schedule->user_id)) {
                return false;
            }
        }

        return true;
    }

    private function resolveVibe(Schedule $schedule): ?Vibe
    {
        if ($schedule->relationLoaded('vibe')) {
            return $schedule->getRelation('vibe');
        }

        return $schedule->vibe;
    }

    /**
     * @return Collection<int, SceneAction>
     */
    private function resolveDeviceActions(Vibe $vibe): Collection
    {
        $scene = $this->resolveScene($vibe);

        if ($scene === null) {
            return new Collection;
        }

        if ($scene->relationLoaded('actions')) {
            return $scene->actions;
        }

        return $scene->actions()
            ->with(['device.providerConnection'])
            ->get();
    }

    private function resolveScene(Vibe $vibe): ?Scene
    {
        if ($vibe->scene_id === null) {
            return null;
        }

        if ($vibe->relationLoaded('scene')) {
            return $vibe->scene;
        }

        return $vibe->scene;
    }

    private function isActionValidForSchedule(SceneAction $action, int $scheduleUserId): bool
    {
        $device = $action->relationLoaded('device')
            ? $action->getRelation('device')
            : $action->device;

        if ($device === null) {
            return false;
        }

        if ($device->user_id !== $scheduleUserId) {
            return false;
        }

        $connection = $device->relationLoaded('providerConnection')
            ? $device->getRelation('providerConnection')
            : $device->providerConnection;

        if ($connection === null) {
            return false;
        }

        if ($connection->user_id !== $scheduleUserId) {
            return false;
        }

        return true;
    }
}
