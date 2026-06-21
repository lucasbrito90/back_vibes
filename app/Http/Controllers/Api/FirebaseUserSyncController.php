<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncFirebaseUserRequest;
use App\Http\Resources\SyncedUserResource;
use App\Services\Auth\SyncFirebaseUser;
use App\Services\Firebase\VerifyFirebaseIdToken;
use Illuminate\Http\JsonResponse;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

final class FirebaseUserSyncController extends Controller
{
    public function __invoke(
        SyncFirebaseUserRequest $request,
        VerifyFirebaseIdToken $verifier,
        SyncFirebaseUser $sync,
    ): JsonResponse {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'message' => 'Missing Firebase ID token.',
            ], 401);
        }

        try {
            $claims = $verifier->verify($token);
        } catch (FailedToVerifyToken) {
            return response()->json([
                'message' => 'Invalid Firebase ID token.',
            ], 401);
        }

        $user = $sync->execute($claims);

        return SyncedUserResource::make($user)->response();
    }
}
