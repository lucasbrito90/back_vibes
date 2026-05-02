<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Symfony\Component\HttpFoundation\Response;

class FirebaseAuthenticate
{
    public function __construct(private readonly FirebaseAuth $firebase) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $verified = $this->firebase->verifyIdToken($token);
        } catch (FailedToVerifyToken) {
            return response()->json(['message' => 'Invalid Firebase token.'], 401);
        }

        $uid = (string) $verified->claims()->get('sub');

        $user = User::where('firebase_uid', $uid)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 401);
        }

        Auth::login($user);

        return $next($request);
    }
}
