<?php

declare(strict_types=1);

namespace App\Services\Firebase;

use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

final readonly class VerifyFirebaseIdToken
{
    public function __construct(private Auth $auth) {}

    /**
     * @throws FailedToVerifyToken
     */
    public function verify(string $token): FirebaseTokenClaims
    {
        $verified = $this->auth->verifyIdToken($token);

        return FirebaseTokenClaims::fromUnencryptedToken($verified);
    }
}
