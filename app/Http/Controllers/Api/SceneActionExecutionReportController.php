<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportSceneActionExecutionRequest;
use App\Models\SceneAction;
use App\Models\SceneActionExecution;
use App\SmartHome\Services\SceneActionExecutionRecorder;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Client-reported scene action execution outcomes (ADR-036 Decision 7).
 *
 * Top-level endpoint — correlation uses scene_execution_id + scene_action_id,
 * not a nested scene route. Distinct from SceneExecutionController (read-only,
 * scoped under scenes/{scene}/executions).
 */
final class SceneActionExecutionReportController extends Controller
{
    /**
     * Accept a device-side execution report from the mobile runtime and persist it.
     *
     * Ownership: scene_action_id must belong to a Scene owned by the authenticated
     * user, enforced via a scoped lookup (not ScenePolicy::view(), which throws 403
     * by default) — a non-owner or a nonexistent scene_action_id both get a plain
     * 404, so a non-owner cannot even confirm the id exists (ADR-036 Decision 7:
     * "reported results are untrusted client input").
     *
     * Idempotency on (scene_execution_id, scene_action_id): check-then-insert
     * inside a transaction, deliberately WITHOUT a new unique index. The
     * scene_action_executions table already holds multiple rows for the same
     * (scene_execution_id, scene_action_id) on the server-side Home Assistant
     * retry path (one row per `attempt`, via SceneActionJob +
     * SceneActionExecutionRecorder) — a unique index would break that. This
     * client-reported path always writes attempt=1 and never models retry, so a
     * duplicate report for the same key is treated as a no-op: still a success
     * response to the caller (it has no way to know the first report already
     * landed), but no second row.
     */
    public function store(ReportSceneActionExecutionRequest $request, SceneActionExecutionRecorder $recorder): JsonResponse
    {
        $validated = $request->validated();

        $sceneAction = SceneAction::query()
            ->whereHas('scene', function ($query) use ($request): void {
                $query->where('user_id', $request->user()->id);
            })
            ->with(['device.providerConnection'])
            ->find($validated['scene_action_id']);

        if ($sceneAction === null) {
            abort(404);
        }

        $device = $sceneAction->device;
        $connection = $device?->providerConnection;

        if ($device === null || $connection === null) {
            abort(404);
        }

        DB::transaction(function () use ($validated, $sceneAction, $device, $connection, $recorder): void {
            $alreadyReported = SceneActionExecution::query()
                ->where('scene_execution_id', $validated['scene_execution_id'])
                ->where('scene_action_id', $sceneAction->id)
                ->exists();

            if ($alreadyReported) {
                return;
            }

            $recorder->record(
                sceneExecutionId: $validated['scene_execution_id'],
                action: $sceneAction,
                device: $device,
                connection: $connection,
                outcome: SmartHomeActionOutcome::from($validated['outcome']),
                executedAt: now(),
                durationMs: $validated['duration_ms'] ?? null,
                attempt: 1,
            );
        });

        return response()->json(['data' => $validated], 202);
    }
}
