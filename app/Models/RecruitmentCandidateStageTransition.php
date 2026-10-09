<?php

namespace App\Models;

use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentCandidateStageTransition extends Model
{
    protected $fillable = [
        'company_id',
        'recruitment_candidate_id',
        'action',
        'from_stage',
        'to_stage',
        'from_outcome',
        'to_outcome',
        'reason',
        'context',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'recruitment_candidate_id' => 'integer',
            'performed_by' => 'integer',
            'action' => CandidateTransitionAction::class,
            'from_stage' => CandidateStage::class,
            'to_stage' => CandidateStage::class,
            'from_outcome' => CandidateInterviewOutcome::class,
            'to_outcome' => CandidateInterviewOutcome::class,
            'context' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCandidate::class, 'recruitment_candidate_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
