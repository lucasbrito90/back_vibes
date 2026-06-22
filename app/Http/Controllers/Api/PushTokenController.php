<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RefreshPushTokenRequest;
use App\Http\Requests\StorePushTokenRequest;
use App\Http\Resources\PushTokenResource;
use App\Models\PushToken;
use App\PushNotifications\Services\PushTokenService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Manages FCM push token registration, rotation, and deactivation.
 *
 * All routes require firebase.auth middleware — user_id is always forced from
 * the authenticated user, never from the request body (ADR-018, ADR-021).
 *
 * Routes:
 *   POST   /api/push-tokens          → store  (register / upsert)
 *   POST   /api/push-tokens/refresh  → refresh (token rotation)
 *   DELETE /api/push-tokens/{pushToken} → destroy (deactivate)
 */
final class PushTokenController extends Controller
{
    use AuthorizesRequests;

    /**
     * Register or reactivate a push token for the authenticated user.
     *
     * Returns 201 on first-time registration, 200 on upsert/reactivation.
     * Laravel's ResourceResponse automatically sets 201 when wasRecentlyCreated is true.
     */
    public function store(StorePushTokenRequest $request, PushTokenService $service): PushTokenResource
    {
        $this->authorize('create', PushToken::class);

        $pushToken = $service->register($request->user(), $request->validated());

        return new PushTokenResource($pushToken);
    }

    /**
     * Handle FCM token rotation for the authenticated user.
     *
     * Deactivates old_token (if owned by current user) and registers the new token.
     * Always returns 200 — rotation is a modification, not a creation event.
     */
    public function refresh(RefreshPushTokenRequest $request, PushTokenService $service): JsonResponse
    {
        $this->authorize('create', PushToken::class);

        $pushToken = $service->refresh($request->user(), $request->validated());

        return (new PushTokenResource($pushToken))->response()->setStatusCode(200);
    }

    /**
     * Deactivate a push token (logout or explicit unregister).
     *
     * Policy ensures the token belongs to the authenticated user before dispatch.
     */
    public function destroy(Request $request, PushToken $pushToken, PushTokenService $service): Response
    {
        $this->authorize('delete', $pushToken);

        $service->deactivate($request->user(), $pushToken);

        return response()->noContent();
    }
}
