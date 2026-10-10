<?php

namespace App\Models;

use App\Enums\Recruitment\CandidateInterviewMode;
use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateJoiningReadinessStatus;
use App\Enums\Recruitment\CandidateSource;
use App\Enums\Recruitment\CandidateStage;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\RecruitmentCandidateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Support\LogOptions;

class RecruitmentCandidate extends Model
{
    /** @use HasFactory<RecruitmentCandidateFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'recruitment_requirement_id',
        'recruitment_requirement_line_id',
        'requirement_number_snapshot',
        'position_title_snapshot',
        'name',
        'email',
        'phone',
        'email_normalized',
        'phone_normalized',
        'nationality_id',
        'source',
        'notes',
        'stage',
        'interview_outcome',
        'rejection_reason',
        'pre_rejection_stage',
        'interview_scheduled_at',
        'interviewer_user_id',
        'external_interviewer_name',
        'interview_mode',
        'interview_location',
        'interview_feedback',
        'cv_path',
        'cv_original_file_name',
        'cv_mime_type',
        'cv_file_size_bytes',
        'cv_file_checksum',
        'expected_joining_date',
        'actual_joining_date',
        'joined_at',
        'joined_by',
        'joining_readiness_status',
        'joining_readiness_notes',
        'joining_blocker_notes',
        'employee_id',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'recruitment_requirement_id' => 'integer',
            'recruitment_requirement_line_id' => 'integer',
            'nationality_id' => 'integer',
            'interviewer_user_id' => 'integer',
            'joined_by' => 'integer',
            'employee_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'cv_file_size_bytes' => 'integer',
            'lock_version' => 'integer',
            'stage' => CandidateStage::class,
            'interview_outcome' => CandidateInterviewOutcome::class,
            'interview_mode' => CandidateInterviewMode::class,
            'source' => CandidateSource::class,
            'interview_scheduled_at' => 'datetime',
            'expected_joining_date' => 'date',
            'actual_joining_date' => 'date',
            'joined_at' => 'datetime',
            'joining_readiness_status' => CandidateJoiningReadinessStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'email',
                'phone',
                'nationality_id',
                'source',
                'notes',
                'stage',
                'interview_outcome',
                'interview_scheduled_at',
                'interviewer_user_id',
                'external_interviewer_name',
                'interview_mode',
                'interview_location',
                'cv_original_file_name',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequirement::class, 'recruitment_requirement_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequirementLine::class, 'recruitment_requirement_line_id');
    }

    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }

    public function interviewerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function stageTransitions(): HasMany
    {
        return $this->hasMany(RecruitmentCandidateStageTransition::class, 'recruitment_candidate_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(RecruitmentCandidateOffer::class, 'recruitment_candidate_id');
    }

    public function currentOffer(): HasOne
    {
        return $this->hasOne(RecruitmentCandidateOffer::class, 'recruitment_candidate_id')
            ->where('is_current', true)
            ->latestOfMany('id');
    }

    public function joinedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'joined_by');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function internalReminders(): HasMany
    {
        return $this->hasMany(RecruitmentCandidateInternalReminder::class, 'recruitment_candidate_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function hasCv(): bool
    {
        return filled($this->cv_path);
    }
}
