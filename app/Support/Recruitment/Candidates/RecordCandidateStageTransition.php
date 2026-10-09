<?php

namespace App\Support\Recruitment\Candidates;

use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateStageTransition;

final class RecordCandidateStageTransition
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public static function handle(
        RecruitmentCandidate $candidate,
        CandidateTransitionAction $action,
        ?CandidateStage $fromStage,
        CandidateStage $toStage,
        ?CandidateInterviewOutcome $fromOutcome,
        ?CandidateInterviewOutcome $toOutcome,
        ?int $performedBy,
        ?string $reason = null,
        ?array $context = null,
    ): RecruitmentCandidateStageTransition {
        return RecruitmentCandidateStageTransition::query()->create([
            'company_id' => (int) $candidate->company_id,
            'recruitment_candidate_id' => (int) $candidate->id,
            'action' => $action,
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'from_outcome' => $fromOutcome,
            'to_outcome' => $toOutcome,
            'reason' => $reason,
            'context' => $context,
            'performed_by' => $performedBy,
        ]);
    }
}
