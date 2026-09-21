<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

/**
 * Summary returned by VibeSmartHomeDispatchService after enqueuing jobs.
 *
 * Immutable. Serialised directly as the API response body.
 */
final readonly class SmartHomeDispatchResult
{
    /**
     * @param  list<int>  $action_ids  IDs of the actions whose jobs were dispatched, in sort_order.
     */
    public function __construct(
        public int $vibe_id,
        public int $dispatched,
        public int $skipped,
        public array $action_ids,
        public string $scene_execution_id,
        /**
         * Actions skipped because their provider does not declare
         * ScheduledExecution capability (ADR-036 Decision 5). Only non-zero
         * when dispatch() is called with requireScheduledExecution = true.
         * Never included in the manual-dispatch API response body.
         */
        public int $skipped_unsupported_execution = 0,
        /**
         * IDs of actions whose provider does not declare ServerSideExecution
         * (ADR-036 Decision 7) — not enqueued as a job. Only populated when
         * dispatch() is called with requireScheduledExecution = false (manual
         * dispatch / vibe play); the scheduler path (true) is unaffected and
         * keeps using skipped_unsupported_execution instead. The mobile
         * runtime executes these itself and reports the outcome via
         * POST /api/scene-action-executions/report. Additive field.
         *
         * @var list<int>
         */
        public array $device_action_ids = [],
    ) {}
}
