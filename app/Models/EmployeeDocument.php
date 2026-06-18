<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use App\Support\EmployeeDocuments\DocumentExpiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class EmployeeDocument extends Model
{
    use LogsActivityWithCompany;
    use SoftDeletes;

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

    public function expiryAlerts(): HasMany
    {
        return $this->hasMany(EmployeeDocumentExpiryAlert::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeLatestUpload(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function scopeWhereExpiryTracked(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date');
    }

    public function scopeWhereWithoutExpiry(Builder $query): Builder
    {
        return $query->whereNull('expiry_date');
    }

    public function scopeWhereExpired(Builder $query): Builder
    {
        return $query
            ->whereExpiryTracked()
            ->whereDate('expiry_date', '<', now()->toDateString());
    }

    public function scopeWhereExpiringWithin(Builder $query, int $days): Builder
    {
        return $query
            ->whereExpiryTracked()
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString());
    }

    public static function deriveStatus(?string $expiryDate): string
    {
        return DocumentExpiry::persistedStatus($expiryDate);
    }

    /**
     * @return array<string, mixed>
     */
    public function toProfileArray(): array
    {
        return [
            ...$this->toBrowseArray(),
            'title' => $this->title,
            'type' => $this->type,
            'document_type_id' => $this->document_type_id,
            'document_type_value' => $this->document_type,
            'document_type_label' => $this->document_type_label,
            'file_path' => $this->file_path,
            'original_filename' => $this->original_filename,
            'document_number' => $this->document_number,
            'notes' => $this->notes,
            'current_version' => $this->current_version,
            'versions_count' => (int) ($this->versions_count ?? 0),
            'uploaded_by' => $this->relationLoaded('uploader') ? $this->uploader?->name : null,
            'created_at' => $this->created_at?->toDateTimeString(),
            'versions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toShowArray(): array
    {
        return [
            ...$this->toProfileArray(),
            'versions' => $this->versions->map(fn (EmployeeDocumentVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'file_url' => $version->file_url,
                'original_filename' => $version->original_filename,
                'mime_type' => $version->mime_type,
                'size_bytes' => $version->size_bytes,
                'replaced_by' => $version->relationLoaded('replacer') ? $version->replacer?->name : null,
                'created_at' => $version->created_at?->toDateTimeString(),
            ])->values()->all(),
        ];
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
     *     uploaded_by: string|null,
     *     mime_type: string|null,
     *     can_preview: bool,
     *     status: string|null,
     *     expiry_date: string|null,
     *     issue_date: string|null,
     *     document_number: string|null,
     *     size_bytes: int|null,
     *     expiry_status: string|null,
     *     remaining_days: int|null,
     *     expiry_label: string
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
            'uploaded_by' => $this->relationLoaded('uploader') ? $this->uploader?->name : null,
            'mime_type' => $this->mime_type,
            'can_preview' => $this->can_preview,
            'status' => $this->status,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'issue_date' => $this->issue_date?->toDateString(),
            'document_number' => $this->document_number,
            'size_bytes' => $this->size_bytes !== null ? (int) $this->size_bytes : null,
            'expiry_status' => DocumentExpiry::resolve($this->expiry_date)?->value,
            'remaining_days' => DocumentExpiry::remainingDays($this->expiry_date),
            'expiry_label' => DocumentExpiry::humanLabel($this->expiry_date),
        ];
    }
}
