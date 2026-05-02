<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vibe;

class VibePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Vibe $vibe): bool
    {
        return $user->id === $vibe->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Vibe $vibe): bool
    {
        return $user->id === $vibe->user_id;
    }

    public function delete(User $user, Vibe $vibe): bool
    {
        return $user->id === $vibe->user_id;
    }
}
