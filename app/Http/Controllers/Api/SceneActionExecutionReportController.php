<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportSceneActionExecutionRequest;
use Illuminate\Http\JsonResponse;

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
     * Accept a device-side execution report from the mobile runtime.
     *
     * Validates payload shape and echoes it back. P07 will persist to
     * scene_action_executions, enforce ownership, and handle idempotency on
     * (scene_execution_id, scene_action_id) — this task deliberately writes
     * nothing to the database.
     */
    public function store(ReportSceneActionExecutionRequest $request): JsonResponse
    {
        return response()->json(['data' => $request->validated()], 202);
    }
}
