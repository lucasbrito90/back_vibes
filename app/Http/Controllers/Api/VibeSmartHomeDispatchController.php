<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vibe;
use App\SmartHome\Services\VibeSmartHomeDispatchService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles a vibe play trigger from mobile.
 *
 * POST /api/vibes/{vibe}/smart-home/dispatch
 *
 * - Authorises via VibePolicy (view permission — user must own the vibe).
 * - Delegates job dispatching to VibeSmartHomeDispatchService.
 * - Returns a dispatch summary.
 * - Never calls Home Assistant or any provider adapter.
 * - Never blocks audio playback on mobile.
 */
final class VibeSmartHomeDispatchController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly VibeSmartHomeDispatchService $dispatchService,
    ) {}

    public function __invoke(Request $request, Vibe $vibe): JsonResponse
    {
        $this->authorize('view', $vibe);

        $result = $this->dispatchService->dispatch($vibe);

        return response()->json([
            'data' => [
                'vibe_id' => $result->vibe_id,
                'dispatched' => $result->dispatched,
                'skipped' => $result->skipped,
                'action_ids' => $result->action_ids,
            ],
        ]);
    }
}
