<?php

declare(strict_types=1);

namespace App\Policies;

use App\Http\Middleware\EnsureAdminApproved;
use App\Models\CoverBundle;
use App\Models\User;

/**
 * Cover bundle catalog authorization.
 *
 * Write routes are guarded with {@see EnsureAdminApproved}.
 * This policy documents intent for future {@see authorize()} wiring.
 */
class CoverBundlePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CoverBundle $coverBundle): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdminApproved();
    }

    public function update(User $user, CoverBundle $coverBundle): bool
    {
        return $user->isAdminApproved();
    }

    public function delete(User $user, CoverBundle $coverBundle): bool
    {
        return $user->isAdminApproved();
    }
}
