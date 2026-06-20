<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProviderConnection;
use App\Models\User;

class ProviderConnectionPolicy
{
    /**
     * Controllers must scope listing queries by auth()->id().
     * This gate only confirms the user is authenticated.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProviderConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ProviderConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }

    public function delete(User $user, ProviderConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }
}
