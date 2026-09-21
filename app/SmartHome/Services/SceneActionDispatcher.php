<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Jobs\SmartHome\SceneActionJob;
use App\Models\SceneAction;

/**
 * Enqueues one SceneActionJob for a scene action, honoring its delay_seconds.
 *
 * Exists so the two server-side dispatch paths — SceneDispatchService (manual
 * Scene execution) and VibeSmartHomeDispatchService (Vibe play and the
 * scheduler) — cannot drift apart on what a delay means. Before this class the
 * field was validated, persisted, exposed by SceneActionResource and edited in
 * the app, but never read at dispatch: every action fired at once, in both
 * paths.
 *
 * Semantics (PO decision, 20/09/2026 — option (a)): the delay is **per action
 * and absolute from the moment the scene is dispatched**, not cumulative along
 * sort_order. That is what the copy the app already shows describes — "Wait
 * this long before the action runs (0-3600s)" — so two actions delayed 10s
 * each both run 10s after execution starts, not at 10s and 20s. The device-side
 * runtime (front_vibes google-home-execution.service.ts) implements the same
 * rule, so a delay does not change meaning with the device's provider.
 *
 * A zero delay enqueues exactly as before — no delay argument is attached at
 * all, so nothing about the existing immediate path changes.
 */
final class SceneActionDispatcher
{
    public function dispatch(SceneAction $action, ?string $sceneExecutionId): void
    {
        $delaySeconds = max(0, $action->delay_seconds);

        $pending = SceneActionJob::dispatch($action->id, $sceneExecutionId);

        if ($delaySeconds > 0) {
            $pending->delay($delaySeconds);
        }
    }
}
