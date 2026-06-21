<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleExecutionResource;
use App\Models\Schedule;
use App\Models\ScheduleExecution;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Execution log sync — Scheduler MVP Phase 11.
 *
 * Exposes schedule execution history to the mobile app and provides a
 * best-effort acknowledgement endpoint for notification tap events.
 *
 * Authorization is scoped through the parent Schedule via SchedulePolicy::view,
 * which checks ownership (user_id). No separate ScheduleExecution policy needed.
 */
final class ScheduleExecutionController extends Controller
{
    use AuthorizesRequests;

    /**
     * GET /api/schedules/{schedule}/executions
     *
     * Returns paginated execution history for the given schedule,
     * ordered by scheduled_for descending (most-recent first).
     */
    public function index(Request $request, Schedule $schedule): AnonymousResourceCollection
    {
        $this->authorize('view', $schedule);

        $executions = $schedule->executions()
            ->orderByDesc('scheduled_for')
            ->paginate(20);

        return ScheduleExecutionResource::collection($executions);
    }

    /**
     * POST /api/schedules/{schedule}/executions/{occurrence_key}/ack
     *
     * Mobile reports the user tapped / opened a schedule notification.
     * Transitions status to `acknowledged` (idempotent — repeated calls are safe).
     * Does NOT guarantee playback or create a new execution record.
     */
    public function acknowledge(Request $request, Schedule $schedule, string $occurrenceKey): JsonResponse
    {
        $this->authorize('view', $schedule);

        $execution = $schedule->executions()
            ->where('occurrence_key', $occurrenceKey)
            ->first();

        if ($execution === null) {
            return response()->json(['message' => 'Execution not found.'], 404);
        }

        if ($execution->status !== 'acknowledged') {
            $execution->status = 'acknowledged';
            $execution->save();
        }

        return (new ScheduleExecutionResource($execution))->response();
    }
}
