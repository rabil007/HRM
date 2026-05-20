<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;

class EmployeeDocument extends Model
{
    use LogsActivityWithCompany;

    protected $guarded = [];

    protected $casts = [
        'issue_date' => 'date:Y-m-d',
        'expiry_date' => 'date:Y-m-d',
        'replaced_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'employee_id',
                'document_type_id',
                'document_type',
                'title',
                'file_path',
                'original_filename',
                'mime_type',
                'size_bytes',
                'checksum',
                'current_version',
                'issue_date',
                'expiry_date',
                'document_number',
                'notes',
                'status',
                'uploaded_by',
            ])
            ->logOnlyDirty();
    }

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

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EmployeeDocumentVersion::class)->latest('version');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeLatestUpload(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
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

    public function getDocumentTypeLabelAttribute(): string
    {
        return $this->documentType?->title
            ?? $this->title
            ?? $this->document_type
            ?? $this->type
            ?? 'Document';
    }

    public function getCanPreviewAttribute(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/')
            || $this->mime_type === 'application/pdf';
    }

    /**
     * @return array{
     *     id: int,
     *     document_name: string,
     *     document_type: string,
     *     file_url: string,
     *     uploaded_at: string|null,
     *     mime_type: string|null,
     *     can_preview: bool,
     *     status: string|null
     * }
     */
    public function toBrowseArray(): array
    {
        return [
            'id' => $this->id,
            'document_name' => $this->original_filename
                ?? $this->title
                ?? $this->document_type_label,
            'document_type' => $this->document_type_label,
            'file_url' => $this->file_url,
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'mime_type' => $this->mime_type,
            'can_preview' => $this->can_preview,
            'status' => $this->status,
        ];
    }
}
