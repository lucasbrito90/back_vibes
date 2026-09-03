<?php

declare(strict_types=1);

namespace App\Telemetry\Logging;

use App\Telemetry\Http\HttpExceptionStatus;
use App\Telemetry\Http\HttpRouteNormalizer;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Illuminate\Routing\Route;
use Monolog\LogRecord;
use Throwable;

/**
 * Laravel log "tap" (Part 6, Phase 7B.1) that enriches existing exception
 * log records with safe, bounded HTTP context — added to every channel the
 * same way TraceCorrelationLogTap already is (TelemetryServiceProvider), so
 * no channel definition needs editing.
 *
 * Deliberately narrow:
 * - Only touches records whose `context.exception` is a Throwable — i.e.
 *   Laravel's own exception logging (`report()`/`Handler::report()`), never
 *   a record an application chose to write itself.
 * - Only adds context when a route has actually resolved on the current
 *   request. In this application, every exception Laravel actually reports
 *   by default occurs after routing succeeded (404/405/422/401 are in
 *   Illuminate's internalDontReport list and are never logged) — so this
 *   also naturally excludes console/queue contexts, which never carry a
 *   resolved HTTP route, without needing to branch on runningInConsole().
 * - Adds only `http_method`, `http_route` (stable template, never a
 *   resolved path or query string) and `http_status_code` (best-effort
 *   estimate via HttpExceptionStatus — the same bounded mapping
 *   HttpRequestTelemetry uses for metrics/span attributes).
 * - Never touches `message` or `context` — only `extra`, exactly like
 *   TraceCorrelationLogTap (logs-philosophy.md §6).
 * - No request body, headers, or PII are ever read.
 */
final class HttpErrorContextLogTap
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
                if (! app()->bound('request')) {
                    return $record;
                }

                $request = app('request');

                if (! $request instanceof Request || ! $request->route() instanceof Route) {
                    return $record;
                }

                $context = [
                    'http_method' => $request->method(),
                    'http_route' => (new HttpRouteNormalizer)->normalize($request),
                    'http_status_code' => HttpExceptionStatus::estimate($exception),
                ];
            } catch (Throwable) {
                return $record;
            }

            $record->extra = array_merge($record->extra, $context);

            return $record;
        });
    }
}
