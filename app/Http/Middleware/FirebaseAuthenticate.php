<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Firebase\VerifyFirebaseIdToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Symfony\Component\HttpFoundation\Response;

class FirebaseAuthenticate
{
    public function __construct(
        private readonly VerifyFirebaseIdToken $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $claims = $this->verifier->verify($token);
        } catch (FailedToVerifyToken) {
            return response()->json(['message' => 'Invalid Firebase token.'], 401);
        }

        $user = User::where('firebase_uid', $claims->uid)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 401);
        }

        Auth::login($user);

        return $next($request);
    }
}
