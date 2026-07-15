<?php

namespace App\Models;

use App\Enums\DocumentShareScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class DocumentShare extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scope' => DocumentShareScope::class,
            'employee_document_ids' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'can_download' => 'boolean',
            'can_upload' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isAccessible(): bool
    {
        return ! $this->isExpired() && ! $this->isRevoked();
    }

    public function hasPassword(): bool
    {
        return filled($this->password_hash);
    }

    public function passwordMatches(string $password): bool
    {
        return $this->hasPassword()
            && Hash::check($password, (string) $this->password_hash);
    }

    public function allowsUpload(): bool
    {
        return $this->scope === DocumentShareScope::Folder && $this->can_upload;
    }

    /**
     * @return list<int>
     */
    public function documentIds(): array
    {
        $ids = $this->employee_document_ids ?? [];

        return array_values(array_map('intval', $ids));
    }
}
