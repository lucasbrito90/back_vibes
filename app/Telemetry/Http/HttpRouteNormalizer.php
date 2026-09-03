<?php

declare(strict_types=1);

namespace App\Telemetry\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Produces a stable, bounded route identifier for telemetry — never a
 * resolved path, never a query string, never a route-parameter value
 * (backend-http-routing-instrumentation.md §"Route normalization").
 *
 * Mirrors the value opentelemetry-auto-laravel itself uses for the
 * `http.route` span attribute (the route's URI template, e.g.
 * `api/vibes/{vibe}`) so the Ixora metric label and the enriched span
 * attribute always agree for the same request.
 */
final class HttpRouteNormalizer
{
    /**
     * Bounded fallback for requests that never resolved to a route: 404
     * (no matching URI), 405 (URI matched, method did not), or any
     * exception thrown before routing completed.
     */
    public const UNMATCHED = 'unmatched';

    public function normalize(Request $request): string
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return self::UNMATCHED;
        }

        $uri = $route->uri();

        if ($uri === '') {
            return '/';
        }

        return '/'.ltrim($uri, '/');
    }
}
