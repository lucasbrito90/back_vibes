<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * Aggregated outcome for one scene execution event (ADR-034 §5).
 */
enum SceneExecutionAggregateState: string
{
    case NoActions = 'no_actions';
    case Success = 'success';
    case Failure = 'failure';
    case PartialSuccess = 'partial_success';

    public static function fromCounts(int $countSuccess, int $countNonSuccess, int $countTotal): self
    {
        if ($countTotal === 0) {
            return self::NoActions;
        }

        if ($countNonSuccess === 0) {
            return self::Success;
        }

        if ($countSuccess === 0) {
            return self::Failure;
        }

        return self::PartialSuccess;
    }
}
