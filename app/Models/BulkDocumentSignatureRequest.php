<?php

namespace App\Models;

use App\Enums\BulkDocumentSignatureRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BulkDocumentSignatureRequest extends Model
{
    use SoftDeletes;

    public const EXPIRY_DAYS = 14;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => BulkDocumentSignatureRequestStatus::class,
            'signed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function employeeDocument(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BulkDocumentEmailBatch::class, 'batch_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isSignable(): bool
    {
        return $this->status === BulkDocumentSignatureRequestStatus::AwaitingSignature
            && ! $this->isExpired();
    }
}
