<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\SyncFirebaseUser;
use App\Services\Firebase\VerifyFirebaseIdToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

class FirebaseAuthController extends Controller
{
    public function store(
        Request $request,
        VerifyFirebaseIdToken $verifier,
        SyncFirebaseUser $sync,
    ): JsonResponse {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return response()->json([
                'message' => 'Missing Firebase ID token.',
            ], 401);
        }

        try {
            $claims = $verifier->verify($bearerToken);
        } catch (FailedToVerifyToken) {
            return response()->json([
                'message' => 'Invalid Firebase ID token.',
            ], 401);
        }

        $user = $sync->execute($claims);

        return response()->json([
            'message' => 'Firebase token validated.',
            'user' => [
                'id' => $user->id,
                'firebase_uid' => $user->firebase_uid,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
