<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\SceneAction;
use App\Models\SceneActionExecution;
use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists one scene_action_executions row per completed action boundary (ADR-034).
 *
 * Fail-open: a DB failure is logged and never affects provider execution or telemetry.
 */
final class SceneActionExecutionRecorder
{
    public function record(
        ?string $sceneExecutionId,
        SceneAction $action,
        Device $device,
        ProviderConnection $connection,
        SmartHomeActionOutcome $outcome,
        Carbon $executedAt,
        ?ActionResult $actionResult = null,
        ?Throwable $exception = null,
        ?string $traceId = null,
        ?int $durationMs = null,
        int $attempt = 1,
    ): void {
        if ($sceneExecutionId === null) {
            return;
        }

        try {
            SceneActionExecution::query()->create([
                'scene_execution_id' => $sceneExecutionId,
                'scene_id' => $action->scene_id,
                'scene_action_id' => $action->id,
                'device_id' => $device->id,
                'provider' => $connection->provider,
                'provider_connection_id' => $connection->id,
                'action_type' => $action->action_type,
                'outcome' => $outcome->value,
                'failure_category' => $this->resolveFailureCategory($outcome, $actionResult, $exception),
                'http_status_code' => $actionResult?->status_code,
                'duration_ms' => $durationMs,
                'trace_id' => $traceId,
                'attempt' => $attempt,
                'executed_at' => $executedAt,
                'created_at' => $executedAt,
            ]);
        } catch (Throwable $e) {
            Log::warning('SceneActionExecutionRecorder: failed to persist execution row.', [
                'scene_execution_id' => $sceneExecutionId,
                'scene_action_id' => $action->id,
                'scene_id' => $action->scene_id,
                'outcome' => $outcome->value,
                'exception_class' => $e::class,
            ]);
        }
    }

    private function resolveFailureCategory(
        SmartHomeActionOutcome $outcome,
        ?ActionResult $actionResult,
        ?Throwable $exception,
    ): ?string {
        if ($outcome === SmartHomeActionOutcome::Success) {
            return null;
        }

        if ($outcome === SmartHomeActionOutcome::Unsupported) {
            return 'unsupported_action';
        }

        if ($outcome === SmartHomeActionOutcome::Unknown) {
            return 'unexpected';
        }

        if ($exception !== null && ! $exception instanceof UnsupportedSmartHomeActionException) {
            return 'unexpected';
        }

        if ($actionResult !== null && $actionResult->status_code === null) {
            return 'transport';
        }

        return 'provider_error';
    }
}
