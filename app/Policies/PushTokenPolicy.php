<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PushToken;
use App\Models\User;

/**
 * Owner-scoped policy for push token management.
 *
 * Users may only view, update, or delete their own tokens.
 * Any authenticated user may register a token (create).
 *
 * References: ADR-018, ADR-021, spec.md §5 Security.
 */
final class PushTokenPolicy
{
    /**
     * Any authenticated user can list — caller must still scope query by user_id.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PushToken $pushToken): bool
    {
        return $user->id === $pushToken->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PushToken $pushToken): bool
    {
        return $user->id === $pushToken->user_id;
    }

    public function delete(User $user, PushToken $pushToken): bool
    {
        return $user->id === $pushToken->user_id;
    }
}
