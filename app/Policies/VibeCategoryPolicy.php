<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VibeCategory;

final class VibeCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, VibeCategory $vibeCategory): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdminApproved();
    }

    public function update(User $user, VibeCategory $vibeCategory): bool
    {
        return $user->isAdminApproved();
    }

    public function delete(User $user, VibeCategory $vibeCategory): bool
    {
        return $user->isAdminApproved();
    }
}
