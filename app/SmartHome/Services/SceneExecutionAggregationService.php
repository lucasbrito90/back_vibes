<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Models\SceneActionExecution;
use App\SmartHome\SceneExecutionAggregateState;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates scene_action_executions rows per execution event (ADR-034 §5).
 *
 * All counts use SQL conditional aggregation — never loads all rows into PHP to
 * compute totals.
 */
final class SceneExecutionAggregationService
{
    private const NON_SUCCESS_OUTCOMES = ['failure', 'unsupported', 'unknown'];

    /**
     * Paginated execution events for a scene (most recent first).
     *
     * @return LengthAwarePaginator<int, object{
     *     scene_execution_id: string,
     *     count_success: int|string,
     *     count_non_success: int|string,
     *     count_total: int|string,
     *     executed_at: string,
     *     state: SceneExecutionAggregateState
     * }>
     */
    public function paginateEventsForScene(int $sceneId, int $perPage = 15): LengthAwarePaginator
    {
        $paginator = DB::table('scene_action_executions')
            ->where('scene_id', $sceneId)
            ->groupBy('scene_execution_id')
            ->selectRaw($this->aggregateSelectColumns())
            ->orderByDesc('executed_at')
            ->paginate($perPage);

        return $paginator->through(function (object $row): object {
            $row->state = $this->stateFromRow($row);

            return $row;
        });
    }

    /**
     * Aggregate summary for one execution event, or null when no rows exist for
     * the scene + execution id pair.
     *
     * @return array{
     *     scene_execution_id: string,
     *     scene_id: int,
     *     state: SceneExecutionAggregateState,
     *     count_success: int,
     *     count_non_success: int,
     *     count_total: int,
     *     executed_at: string|null,
     *     by_provider: list<array{provider: string, count_success: int, count_non_success: int}>
     * }|null
     */
    public function summarizeExecution(int $sceneId, string $sceneExecutionId): ?array
    {
        $row = DB::table('scene_action_executions')
            ->where('scene_id', $sceneId)
            ->where('scene_execution_id', $sceneExecutionId)
            ->selectRaw($this->aggregateSelectColumns())
            ->groupBy('scene_execution_id')
            ->first();

        if ($row === null) {
            return null;
        }

        $countSuccess = (int) $row->count_success;
        $countNonSuccess = (int) $row->count_non_success;
        $countTotal = (int) $row->count_total;

        return [
            'scene_execution_id' => $sceneExecutionId,
            'scene_id' => $sceneId,
            'state' => SceneExecutionAggregateState::fromCounts($countSuccess, $countNonSuccess, $countTotal),
            'count_success' => $countSuccess,
            'count_non_success' => $countNonSuccess,
            'count_total' => $countTotal,
            'executed_at' => $row->executed_at,
            'by_provider' => $this->byProviderBreakdown($sceneId, $sceneExecutionId),
        ];
    }

    /**
     * @return Collection<int, SceneActionExecution>
     */
    public function actionRowsForExecution(int $sceneId, string $sceneExecutionId): Collection
    {
        return SceneActionExecution::query()
            ->where('scene_id', $sceneId)
            ->where('scene_execution_id', $sceneExecutionId)
            ->orderBy('executed_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<array{provider: string, count_success: int, count_non_success: int}>
     */
    public function byProviderBreakdown(int $sceneId, string $sceneExecutionId): array
    {
        return DB::table('scene_action_executions')
            ->where('scene_id', $sceneId)
            ->where('scene_execution_id', $sceneExecutionId)
            ->groupBy('provider')
            ->selectRaw("
                provider,
                SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as count_success,
                SUM(CASE WHEN outcome IN ('".implode("','", self::NON_SUCCESS_OUTCOMES)."') THEN 1 ELSE 0 END) as count_non_success
            ")
            ->orderBy('provider')
            ->get()
            ->map(fn (object $row): array => [
                'provider' => (string) $row->provider,
                'count_success' => (int) $row->count_success,
                'count_non_success' => (int) $row->count_non_success,
            ])
            ->values()
            ->all();
    }

    private function aggregateSelectColumns(): string
    {
        $nonSuccessList = implode("','", self::NON_SUCCESS_OUTCOMES);

        return "
            scene_execution_id,
            SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as count_success,
            SUM(CASE WHEN outcome IN ('{$nonSuccessList}') THEN 1 ELSE 0 END) as count_non_success,
            COUNT(*) as count_total,
            MAX(executed_at) as executed_at
        ";
    }

    private function stateFromRow(object $row): SceneExecutionAggregateState
    {
        return SceneExecutionAggregateState::fromCounts(
            (int) $row->count_success,
            (int) $row->count_non_success,
            (int) $row->count_total,
        );
    }
}
