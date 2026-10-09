<?php

namespace App\Models;

use App\Enums\Recruitment\CandidateOfferStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;

class RecruitmentCandidateOffer extends Model
{
    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'recruitment_candidate_id',
        'revision_number',
        'is_current',
        'supersedes_offer_id',
        'status',
        'salary_amount',
        'salary_currency_code',
        'proposed_joining_date',
        'offer_date',
        'expiry_date',
        'notes',
        'sent_at',
        'sent_by',
        'accepted_at',
        'accepted_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'revision_reason',
        'offer_document_path',
        'offer_document_original_file_name',
        'offer_document_mime_type',
        'offer_document_file_size_bytes',
        'offer_document_file_checksum',
        'acceptance_document_path',
        'acceptance_document_original_file_name',
        'acceptance_document_mime_type',
        'acceptance_document_file_size_bytes',
        'acceptance_document_file_checksum',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'recruitment_candidate_id' => 'integer',
            'revision_number' => 'integer',
            'is_current' => 'boolean',
            'supersedes_offer_id' => 'integer',
            'status' => CandidateOfferStatus::class,
            'salary_amount' => 'decimal:2',
            'proposed_joining_date' => 'date',
            'offer_date' => 'date',
            'expiry_date' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'sent_by' => 'integer',
            'accepted_by' => 'integer',
            'rejected_by' => 'integer',
            'offer_document_file_size_bytes' => 'integer',
            'acceptance_document_file_size_bytes' => 'integer',
            'lock_version' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status',
                'salary_amount',
                'salary_currency_code',
                'proposed_joining_date',
                'offer_date',
                'expiry_date',
                'notes',
                'revision_number',
                'is_current',
                'offer_document_original_file_name',
                'acceptance_document_original_file_name',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCandidate::class, 'recruitment_candidate_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_offer_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_offer_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function hasOfferDocument(): bool
    {
        return filled($this->offer_document_path);
    }

    public function hasAcceptanceDocument(): bool
    {
        return filled($this->acceptance_document_path);
    }
}
