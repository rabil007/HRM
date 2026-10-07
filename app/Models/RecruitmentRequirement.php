<?php

namespace App\Models;

use App\Enums\Recruitment\RequirementDeadlineExtensionStatus;
use App\Enums\Recruitment\RequirementPriority;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\RecruitmentRequirementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class RecruitmentRequirement extends Model
{
    /** @use HasFactory<RecruitmentRequirementFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'requirement_number',
        'client_id',
        'project_id',
        'client_reference_number',
        'request_received_date',
        'required_by_date',
        'location',
        'priority',
        'assigned_to',
        'notes',
        'status',
        'repeated_from_id',
        'opened_at',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'returned_at',
        'returned_by',
        'return_reason',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'client_id' => 'integer',
            'project_id' => 'integer',
            'assigned_to' => 'integer',
            'repeated_from_id' => 'integer',
            'submitted_by' => 'integer',
            'approved_by' => 'integer',
            'returned_by' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'priority' => RequirementPriority::class,
            'status' => RequirementStatus::class,
            'request_received_date' => 'date',
            'required_by_date' => 'date',
            'opened_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'requirement_number',
                'client_id',
                'project_id',
                'client_reference_number',
                'request_received_date',
                'required_by_date',
                'location',
                'priority',
                'assigned_to',
                'status',
                'cancellation_reason',
                'return_reason',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignedRecruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function repeatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'repeated_from_id');
    }

    public function repeatedRequirements(): HasMany
    {
        return $this->hasMany(self::class, 'repeated_from_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecruitmentRequirementLine::class, 'recruitment_requirement_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RecruitmentRequirementAttachment::class, 'recruitment_requirement_id');
    }

    public function notificationRecipients(): HasMany
    {
        return $this->hasMany(RecruitmentRequirementNotificationRecipient::class, 'recruitment_requirement_id');
    }

    public function statusTransitions(): HasMany
    {
        return $this->hasMany(RecruitmentRequirementStatusTransition::class, 'recruitment_requirement_id');
    }

    public function deadlineExtensions(): HasMany
    {
        return $this->hasMany(RecruitmentRequirementDeadlineExtension::class, 'recruitment_requirement_id');
    }

    public function pendingDeadlineExtension(): HasOne
    {
        return $this->hasOne(RecruitmentRequirementDeadlineExtension::class, 'recruitment_requirement_id')
            ->ofMany(['id' => 'max'], function ($query): void {
                $query->where('status', RequirementDeadlineExtensionStatus::Pending);
            });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', RequirementStatus::activeListStatuses());
    }

    public function scopeOnHold(Builder $query): Builder
    {
        return $query->where('status', RequirementStatus::OnHold);
    }

    public function scopeHistory(Builder $query): Builder
    {
        return $query->whereIn('status', RequirementStatus::historyListStatuses());
    }
}
