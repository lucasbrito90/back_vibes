<?php

namespace App\Policies;

use App\Http\Middleware\EnsureAdminApproved;
use App\Models\Sound;
use App\Models\User;

/**
 * Catalog sound authorization.
 *
 * Today write routes are guarded with {@see EnsureAdminApproved}.
 * This policy documents intent and can replace route middleware once wired via authorize().
 */
class SoundPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Sound $sound): bool
    {
        return true;
    }

    /** Filter catalog lists to inactive sounds (approved admins only). */
    public function viewInactive(User $user): bool
    {
        return $user->isAdminApproved();
    }

    public function create(User $user): bool
    {
        return $user->isAdminApproved();
    }

    public function update(User $user, Sound $sound): bool
    {
        return $user->isAdminApproved();
    }

    public function delete(User $user, Sound $sound): bool
    {
        return $user->isAdminApproved();
    }
}
