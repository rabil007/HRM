<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PlatformAccess;
use App\Models\Concerns\LogsActivityWithCompany;
use App\Support\Auth\InvalidateUserSessions;
use App\Support\Auth\RevokeDisabledUserAccess;
use App\Support\Auth\UserEmailIdentity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['company_id', 'name', 'email', 'password', 'avatar', 'status', 'last_login_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'remember_token', 'platform_access', 'active_login_email'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, HasRoles, Notifiable, TwoFactorAuthenticatable;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged('status')) {
                app(RevokeDisabledUserAccess::class)->handle($user);
            }

            if ($user->wasChanged('password')) {
                app(InvalidateUserSessions::class)->handleForPasswordChange($user);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'name',
                'email',
                'avatar',
                'status',
                'last_login_at',
            ])
            ->logOnlyDirty();
    }

    /**
     * Persist emails in Fortify's canonical form when lowercase usernames are on.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: function (mixed $value): ?string {
                if ($value === null) {
                    return null;
                }

                return UserEmailIdentity::normalize((string) $value);
            },
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'platform_access' => PlatformAccess::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['status'])
            ->withTimestamps();
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * @return HasMany<NavigationFavorite, $this>
     */
    public function navigationFavorites(): HasMany
    {
        return $this->hasMany(NavigationFavorite::class);
    }

    /**
     * @return HasMany<RecentItem, $this>
     */
    public function recentItems(): HasMany
    {
        return $this->hasMany(RecentItem::class);
    }

    /**
     * @return HasMany<SavedView, $this>
     */
    public function savedViews(): HasMany
    {
        return $this->hasMany(SavedView::class);
    }
}
