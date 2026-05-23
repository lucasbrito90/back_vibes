<?php

use App\Http\Middleware\EnsureAdminApproved;
use App\Http\Middleware\FirebaseAuthenticate;
use Fruitcake\Cors\CorsService;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'firebase.auth' => FirebaseAuthenticate::class,
            'admin.approved' => EnsureAdminApproved::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // When middleware throws before returning (e.g. PostTooLarge), HandleCors never reaches
        // addActualRequestHeaders(); mirror CORS paths so API errors still expose ACAO when applicable.
        $exceptions->respond(function (SymfonyResponse $response, \Throwable $e, Request $request): SymfonyResponse {
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
