<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use App\Support\EmployeeDocuments\DocumentExpiry;
use Database\Factories\CompanyDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class CompanyDocument extends Model
{
    /** @use HasFactory<CompanyDocumentFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'document_type_id',
        'title',
        'document_number',
        'issue_date',
        'expiry_date',
        'notes',
        'file_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'checksum',
        'current_version',
        'replaced_at',
        'uploaded_by',
        'replaced_by',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'document_type_id',
                'title',
                'document_number',
                'issue_date',
                'expiry_date',
                'notes',
                'original_filename',
                'mime_type',
                'size_bytes',
                'checksum',
                'current_version',
                'replaced_at',
                'uploaded_by',
                'replaced_by',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'replaced_at' => 'datetime',
            'size_bytes' => 'integer',
            'current_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function replacer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CompanyDocumentVersion::class)->latest('version');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function getExpiryStatusAttribute(): string
    {
        return DocumentExpiry::persistedStatus($this->expiry_date);
    }

    public function getExpiryLabelAttribute(): string
    {
        return DocumentExpiry::humanLabel($this->expiry_date);
    }

    public function getRemainingDaysAttribute(): ?int
    {
        return DocumentExpiry::remainingDays($this->expiry_date);
    }

    public function getCanPreviewAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'image/') || $this->mime_type === 'application/pdf';
    }
}
