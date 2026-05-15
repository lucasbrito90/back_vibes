<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /**
     * GET /api/health
     *
     * Public endpoint — no authentication required.
     * Returns 200 OK when the application and database are reachable,
     * or 503 Service Unavailable when the database check fails.
     *
     * Response body intentionally omits internal details (versions, paths,
     * connection strings) to avoid information disclosure.
     */
    public function index(): JsonResponse
    {
        $dbStatus = 'ok';
        $httpStatus = 200;

        try {
            DB::select('select 1');
        } catch (\Throwable) {
            $dbStatus  = 'failed';
            $httpStatus = 503;
        }

        if ($dbStatus !== 'ok') {
            return response()->json([
                'status'   => 'error',
                'database' => 'failed',
            ], 503);
        }

        return response()->json([
            'status'      => 'ok',
            'environment' => app()->environment(),
            'app'         => config('app.name'),
            'timestamp'   => now()->toIso8601String(),
            'database'    => 'ok',
        ], 200);
    }
}
