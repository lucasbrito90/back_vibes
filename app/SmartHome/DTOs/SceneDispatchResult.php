<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

/**
 * Summary returned by SceneDispatchService after enqueuing jobs.
 *
 * Immutable. Serialised directly as the API response body.
 */
final readonly class SceneDispatchResult
{
    /**
     * @param  list<int>  $action_ids  IDs of the actions whose jobs were dispatched, in sort_order.
     * @param  list<int>  $device_action_ids  IDs of actions whose provider does not declare
     *                                        ServerSideExecution (ADR-036 Decision 7) — not enqueued
     *                                        as a job. The mobile runtime executes these itself and
     *                                        reports the outcome via
     *                                        POST /api/scene-action-executions/report. Additive field:
     *                                        existing consumers reading only action_ids are unaffected.
     */
    public function __construct(
        public int $scene_id,
        public int $dispatched,
        public int $skipped,
        public array $action_ids,
        public string $scene_execution_id,
        public array $device_action_ids = [],
    ) {}
}
