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

        return DB::transaction(function () use ($actor, $candidate, $guard): RecruitmentCandidate {
            $graph = CandidateWorkflowAuthorization::lockCandidateOfferGraph($candidate);
            $locked = $graph['candidate'];

            CandidateWorkflowAuthorization::assertCanMove($actor, $locked);
            CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                array_key_exists('lock_version', $guard) ? (int) $guard['lock_version'] : null,
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

            if ($graph['offer'] !== null) {
                throw ValidationException::withMessages([
                    'candidate' => 'Selection cannot be undone after an Offer/JOL has been prepared. Revise or manage the offer instead.',
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
