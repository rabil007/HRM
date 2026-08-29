<?php

namespace App\Models;

use App\Enums\DocumentSigningFlowStatus;
use Database\Factories\DocumentSigningFlowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentSigningFlow extends Model
{
    /** @use HasFactory<DocumentSigningFlowFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_instance_id',
        'document_signing_preset_id',
        'starting_document_instance_version_id',
        'preset_name_snapshot',
        'routing_definition_snapshot',
        'status',
        'current_step_sequence',
        'started_by',
        'started_at',
        'blocked_at',
        'blocked_reason',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentSigningFlowStatus::class,
            'routing_definition_snapshot' => 'array',
            'started_at' => 'datetime',
            'blocked_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentInstance(): BelongsTo
    {
        return $this->belongsTo(DocumentInstance::class);
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(DocumentSigningPreset::class, 'document_signing_preset_id');
    }

    public function startingVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentInstanceVersion::class, 'starting_document_instance_version_id');
    }

    public function startedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function recipientRequests(): HasMany
    {
        return $this->hasMany(DocumentRecipientRequest::class)
            ->orderBy('signing_step_sequence');
    }

    /**
     * @param  Builder<DocumentSigningFlow>  $query
     * @return Builder<DocumentSigningFlow>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<DocumentSigningFlow>  $query
     * @return Builder<DocumentSigningFlow>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DocumentSigningFlowStatus::Active,
            DocumentSigningFlowStatus::Blocked,
        ]);
    }
}
