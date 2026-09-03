<?php

declare(strict_types=1);

namespace App\Telemetry\Logging;

use App\Telemetry\Contracts\LoggerCorrelation;
use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

/**
 * Laravel log "tap" (see config/logging.php driver docs) that pushes a
 * Monolog processor onto every resolved channel — added programmatically to
 * every channel's config by TelemetryServiceProvider so no channel
 * definition needs to be edited by hand.
 *
 * The processor reads whichever span is active *at the moment each log line
 * is written* (via the LoggerCorrelation contract, never the OpenTelemetry
 * SDK directly) and merges trace_id / span_id into the record's `extra`
 * array. It never touches `message` or `context` — existing log messages
 * are never altered (logs-philosophy.md §6, Phase 7A boundary).
 */
final class TraceCorrelationLogTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! method_exists($monolog, 'pushProcessor')) {
            return;
        }

        $monolog->pushProcessor(static function (LogRecord $record): LogRecord {
            try {
                $correlation = app(LoggerCorrelation::class)->current();
            } catch (Throwable) {
                return $record;
            }

            if ($correlation === []) {
                return $record;
            }

            $record->extra = array_merge($record->extra, $correlation);

            return $record;
        });
    }
}
