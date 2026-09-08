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
     */
    public function __construct(
        public int $scene_id,
        public int $dispatched,
        public int $skipped,
        public array $action_ids,
        public string $scene_execution_id,
    ) {}
}
