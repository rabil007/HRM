<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    protected $guarded = [];

    protected $casts = [
        'issue_date' => 'date:Y-m-d',
        'expiry_date' => 'date:Y-m-d',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeExpiringSoon(Builder $query): Builder
    {
        return $query->where('status', 'expiring_soon');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'expired');
    }

    public static function deriveStatus(?string $expiryDate): string
    {
        if (! $expiryDate) {
            return 'valid';
        }

        $expiry = Carbon::parse($expiryDate);
        $now = now();

        if ($expiry->lt($now)) {
            return 'expired';
        }

        if ($expiry->lt($now->copy()->addDays(30))) {
            return 'expiring_soon';
        }

        return 'valid';
    }

    public function getFileUrlAttribute(): string
    {
        if (str_starts_with($this->file_path, 'http')) {
            return $this->file_path;
        }

        return asset('storage/'.ltrim($this->file_path, '/'));
    }
}
