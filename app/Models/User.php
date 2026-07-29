<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

#[Fillable(['name',
    'avatar_key', 'email', 'password'])]
#[Hidden([
    'password',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

    use TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'last_login_at' => 'datetime',
            'last_logout_at' => 'datetime',
            'must_change_password' => 'boolean',
            'temporary_password_expires_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'admin_2fa_grace_expires_at' => 'datetime',
        ];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot([
                'role',
                'status',
                'is_default',
            ])
            ->withTimestamps();
    }

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(
            Organization::class,
            'current_organization_id'
        );
    }

    public function belongsToOrganization(Organization|int $organization): bool
    {
        $organizationId = $organization instanceof Organization
            ? $organization->getKey()
            : $organization;

        return $this->organizations()
            ->whereKey($organizationId)
            ->wherePivot('status', 'active')
            ->exists();
    }

    public function roleForOrganization(Organization|int $organization): ?string
    {
        $organizationId = $organization instanceof Organization
            ? $organization->getKey()
            : $organization;

        $membership = $this->organizations()
            ->whereKey($organizationId)
            ->wherePivot('status', 'active')
            ->first();

        return $membership?->pivot?->role;
    }

    public function isAdministrative(): bool
    {
        return $this->is_platform_admin
            || (
                $this->current_organization_id !== null
                && $this->roleForOrganization($this->current_organization_id)
                    === 'organization_admin'
            );
    }

    public function hasAdministrativeSecondFactor(): bool
    {
        return $this->hasEnabledTwoFactorAuthentication()
            || $this->hasPasskeysEnabled();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
