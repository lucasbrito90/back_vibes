<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;
use Throwable;

/**
 * Classifies SceneActionJob failures as retriable or terminal (pre-v1.4.0 fix).
 *
 * Retriable: transport failure (null status_code) or provider 5xx.
 * Terminal: unsupported actions, 4xx, and non-transport Throwables (e.g. config errors).
 */
final class SceneActionRetryPolicy
{
    public const RELEASE_DELAY_SECONDS = 5;

    public function isRetriable(?ActionResult $result, ?Throwable $exception): bool
    {
        if ($exception instanceof UnsupportedSmartHomeActionException) {
            return false;
        }

        if ($exception !== null) {
            return false;
        }

        if ($result === null || $result->success) {
            return false;
        }

        $statusCode = $result->status_code;

        return $statusCode === null || $statusCode >= 500;
    }
}
