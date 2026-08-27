<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class UserInvitation extends Model
{
    protected $fillable = [
        'company_id',
        'email',
        'name',
        'role_id',
        'employee_id',
        'invited_by',
        'token_hash',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'role_id' => 'integer',
        ];
    }

    public function isInvalidOrExpired(): bool
    {
        return $this->accepted_at !== null
            || $this->revoked_at !== null
            || $this->expires_at->isPast();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
