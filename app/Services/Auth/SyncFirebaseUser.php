<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Firebase\FirebaseTokenClaims;

final class SyncFirebaseUser
{
    public function execute(FirebaseTokenClaims $claims): User
    {
        $user = User::query()->firstOrNew([
            'firebase_uid' => $claims->uid,
        ]);

        $user->name = $claims->resolvedName();
        $user->email = $claims->resolvedEmail();

        if ($claims->picture !== null && $claims->picture !== '') {
            $user->avatar_url = $claims->picture;
        }

        $user->save();

        return $user->fresh() ?? $user;
    }
}
