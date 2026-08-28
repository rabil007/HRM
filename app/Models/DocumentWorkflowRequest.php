<?php

namespace App\Models;

use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowStageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DocumentWorkflowRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_instance_id',
        'document_instance_version_id',
        'status',
        'requested_by',
        'requester_name_snapshot',
        'requested_at',
        'completed_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentWorkflowRequestStatus::class,
            'requested_at' => 'datetime',
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

    public function documentInstanceVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentInstanceVersion::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(DocumentWorkflowStage::class)
            ->orderBy('sequence');
    }

    public function activeStage(): HasOne
    {
        return $this->hasOne(DocumentWorkflowStage::class)
            ->where('status', DocumentWorkflowStageStatus::Active);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DocumentWorkflowRequestStatus::Pending);
    }
}
