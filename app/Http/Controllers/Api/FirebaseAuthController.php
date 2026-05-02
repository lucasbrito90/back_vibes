<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

class FirebaseAuthController extends Controller
{
    public function store(Request $request, Auth $auth): JsonResponse
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return response()->json([
                'message' => 'Missing Firebase ID token.',
            ], 401);
        }

        try {
            $verifiedToken = $auth->verifyIdToken($bearerToken);
        } catch (FailedToVerifyToken) {
            return response()->json([
                'message' => 'Invalid Firebase ID token.',
            ], 401);
        }

        $claims = $verifiedToken->claims();
        $uid = (string) $claims->get('sub');
        $email = $claims->get('email');
        $name = $claims->get('name');

        if (! $email) {
            $email = "{$uid}@firebase.local";
        }

        $user = User::updateOrCreate(
            ['firebase_uid' => $uid],
            [
                'name' => $name ?: 'Firebase User',
                'email' => $email,
            ],
        );

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
