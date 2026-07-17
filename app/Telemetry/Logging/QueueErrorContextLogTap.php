<?php

declare(strict_types=1);

namespace App\Telemetry\Logging;

use App\Telemetry\Queue\QueueExecutionTelemetry;
use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

/**
 * Laravel log "tap" (Part 9, Phase 7B.2) that enriches existing job-failure
 * log records with safe, bounded queue context — added to every channel
 * the same way TraceCorrelationLogTap and HttpErrorContextLogTap already
 * are (TelemetryServiceProvider), so no channel definition needs editing.
 *
 * Deliberately narrow, mirroring App\Telemetry\Logging\HttpErrorContextLogTap:
 * - Only touches records whose `context.exception` is a Throwable.
 * - Only adds context when that exact exception object was seen by
 *   QueueExecutionTelemetry's JobExceptionOccurred/JobFailed listener —
 *   looked up by object identity via QueueExecutionTelemetry::
 *   contextForException(), never by "the most recently processed job"
 *   (see that class's docblock §"Log correlation" for why an ambient
 *   lookup would be unsafe in a long-running worker).
 * - Adds only `queue`, `connection`, `job_name`, `attempt` — never a
 *   payload, job ID, or UUID.
 * - Never touches `message` or `context` — only `extra`, exactly like the
 *   other Phase 7A/7B log taps (logs-philosophy.md §6).
 * - A record with no matching exception (e.g. an HTTP request log, or an
 *   application log unrelated to queue processing) is left completely
 *   untouched — this is what keeps queue context from ever appearing on
 *   an HTTP-only log record, and vice versa (Part 9's log-separation
 *   requirement).
 */
final class QueueErrorContextLogTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! method_exists($monolog, 'pushProcessor')) {
            return;
        }

        $monolog->pushProcessor(static function (LogRecord $record): LogRecord {
            $exception = $record->context['exception'] ?? null;

            if (! $exception instanceof Throwable) {
                return $record;
            }

            try {
                if (! app()->bound(QueueExecutionTelemetry::class)) {
                    return $record;
                }

                $context = app(QueueExecutionTelemetry::class)->contextForException($exception);

                if ($context === null) {
                    return $record;
                }
            } catch (Throwable) {
                return $record;
            }

            $record->extra = array_merge($record->extra, $context->toLogContext());

            return $record;
        });
    }
}
