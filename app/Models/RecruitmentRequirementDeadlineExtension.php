<?php

namespace App\Models;

use App\Enums\Recruitment\RequirementDeadlineExtensionInitiator;
use App\Enums\Recruitment\RequirementDeadlineExtensionStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class RecruitmentRequirementDeadlineExtension extends Model
{
    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'recruitment_requirement_id',
        'requested_by',
        'initiator',
        'old_deadline',
        'requested_deadline',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status',
                'old_deadline',
                'requested_deadline',
                'reason',
                'decided_by',
                'decided_at',
                'decision_note',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'recruitment_requirement_id' => 'integer',
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'initiator' => RequirementDeadlineExtensionInitiator::class,
            'status' => RequirementDeadlineExtensionStatus::class,
            'old_deadline' => 'date',
            'requested_deadline' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequirement::class, 'recruitment_requirement_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', RequirementDeadlineExtensionStatus::Pending);
    }
}
