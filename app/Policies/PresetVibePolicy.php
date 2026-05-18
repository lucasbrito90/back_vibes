<?php

declare(strict_types=1);

namespace App\Policies;

use App\Http\Middleware\EnsureAdminApproved;
use App\Models\PresetVibe;
use App\Models\User;

/**
 * Preset vibe catalog authorization.
 *
 * Write routes use {@see EnsureAdminApproved}.
 */
class PresetVibePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PresetVibe $presetVibe): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdminApproved();
    }

    public function update(User $user, PresetVibe $presetVibe): bool
    {
        return $user->isAdminApproved();
    }

    public function delete(User $user, PresetVibe $presetVibe): bool
    {
        return $user->isAdminApproved();
    }
}
