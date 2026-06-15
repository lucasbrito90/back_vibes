<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'firebase_uid', 'timezone', 'avatar_url', 'role', 'admin_access_status'])]
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function vibes(): HasMany
    {
        return $this->hasMany(Vibe::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function providerConnections(): HasMany
    {
        return $this->hasMany(ProviderConnection::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSettings::class);
    }

    public function adminAccessRequests(): HasMany
    {
        return $this->hasMany(AdminAccessRequest::class);
    }

    public function isAdminApproved(): bool
    {
        return $this->role === 'admin' && $this->admin_access_status === 'approved';
    }
}
