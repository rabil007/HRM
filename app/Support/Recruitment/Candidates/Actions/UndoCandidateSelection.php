<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UndoCandidateSelection
{
    /**
     * @param  array{lock_version?: int|null, expected_stage?: string|null, expected_outcome?: string|null}  $guard
     */
    public function handle(User $actor, RecruitmentCandidate $candidate, array $guard = []): RecruitmentCandidate
    {
        CandidateWorkflowAuthorization::assertCanMove($actor, $candidate);
        CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($candidate, (int) $candidate->company_id);

        return DB::transaction(function () use ($actor, $candidate, $guard): RecruitmentCandidate {
            /** @var RecruitmentCandidate $locked */
            $locked = RecruitmentCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                isset($guard['lock_version']) ? (int) $guard['lock_version'] : null,
                $guard['expected_stage'] ?? CandidateStage::Interview->value,
                $guard['expected_outcome'] ?? CandidateInterviewOutcome::Selected->value,
            );

            if ($locked->stage !== CandidateStage::Interview
                || $locked->interview_outcome !== CandidateInterviewOutcome::Selected
            ) {
                throw ValidationException::withMessages([
                    'interview_outcome' => 'Only selected Interview candidates can have selection undone.',
                ]);
            }

            $locked->fill([
                'interview_outcome' => null,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ]);
            $locked->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::UndoSelected,
                CandidateStage::Interview,
                CandidateStage::Interview,
                CandidateInterviewOutcome::Selected,
                null,
                (int) $actor->id,
            );

            return $locked->refresh();
        });
    }
}
