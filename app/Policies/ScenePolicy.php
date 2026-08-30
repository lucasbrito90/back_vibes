<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Scene;
use App\Models\User;

class ScenePolicy
{
    /**
     * Controllers must scope listing queries by auth()->id().
     * This gate only confirms the user is authenticated.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Scene $scene): bool
    {
        return $user->id === $scene->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Scene $scene): bool
    {
        return $user->id === $scene->user_id;
    }

    public function delete(User $user, Scene $scene): bool
    {
        return $user->id === $scene->user_id;
    }
}
