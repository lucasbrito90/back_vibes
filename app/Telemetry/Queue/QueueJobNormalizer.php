<?php

declare(strict_types=1);

namespace App\Telemetry\Queue;

use Illuminate\Contracts\Queue\Job;
use Throwable;

/**
 * Produces a stable, bounded job name for telemetry — the class basename
 * of the resolved job class, never a serialized payload, UUID, or job ID
 * (backend-queue-console-instrumentation.md §"Queue/command normalization").
 *
 * Uses Job::resolveQueuedJobClass() — the same idiomatic Laravel accessor
 * the official auto-instrumentation's Worker hook consults via
 * resolveName() — which already resolves "wrapped" jobs (e.g. queued
 * closures, `Illuminate\Queue\CallQueuedHandler`-backed command jobs) to
 * their real underlying class name.
 */
final class QueueJobNormalizer
{
    /**
     * Bounded fallback when the job class cannot be resolved (malformed
     * payload, custom Job implementation that doesn't follow the normal
     * resolveQueuedJobClass() contract, etc.).
     */
    public const UNKNOWN = 'unknown';

    public function normalize(Job $job): string
    {
        try {
            $class = $job->resolveQueuedJobClass();
        } catch (Throwable) {
            return self::UNKNOWN;
        }

        if (! is_string($class) || $class === '') {
            return self::UNKNOWN;
        }

        $basename = class_basename($class);

        return $basename === '' ? self::UNKNOWN : $basename;
    }
}
