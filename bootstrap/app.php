<?php

use App\Http\Middleware\EnsureAdminApproved;
use App\Http\Middleware\EnsureDiagnosticsEnvironment;
use App\Http\Middleware\FirebaseAuthenticate;
use App\Http\Middleware\HttpTelemetryMiddleware;
use Fruitcake\Cors\CorsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('smart-home:prune-executions')->dailyAt('03:00');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'firebase.auth' => FirebaseAuthenticate::class,
            'admin.approved' => EnsureAdminApproved::class,
            'diagnostics.non_production' => EnsureDiagnosticsEnvironment::class,
        ]);

        // Phase 7B.1 (Observability Foundation) — HTTP + routing telemetry.
        // Appended (not prepended) to the global stack so it is the innermost
        // global middleware: it wraps routing itself (able to observe 404/405)
        // while running exactly once per request, for every route (web, api,
        // and the framework health route) — see
        // App\Http\Middleware\HttpTelemetryMiddleware and
        // backend-http-routing-instrumentation.md §"Middleware / request lifecycle".
        $middleware->append(HttpTelemetryMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // When middleware throws before returning (e.g. PostTooLarge), HandleCors never reaches
        // addActualRequestHeaders(); mirror CORS paths so API errors still expose ACAO when applicable.
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request): SymfonyResponse {
            foreach (array_filter(config('cors.paths', []), fn (mixed $path): bool => is_string($path)) as $pattern) {
                $path = $pattern !== '/' ? trim($pattern, '/') : '/';
                if ($request->fullUrlIs($path) || $request->is($path)) {
                    $service = new CorsService;
                    $service->setOptions(config('cors', []));

                    return $service->addActualRequestHeaders($response, $request);
                }
            }

            return $response;
        });
    })->create();
