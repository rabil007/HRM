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

final class ReopenRejectedCandidate
{
    /**
     * @param  array{lock_version?: int|null, expected_stage?: string|null}  $guard
     */
    public function handle(
        User $actor,
        RecruitmentCandidate $candidate,
        string $reason,
        array $guard = [],
    ): RecruitmentCandidate {
        CandidateWorkflowAuthorization::assertCanReopenRejected($actor, $candidate);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reopen reason is required.',
            ]);
        }

        return DB::transaction(function () use ($actor, $candidate, $reason, $guard): RecruitmentCandidate {
            $graph = CandidateWorkflowAuthorization::lockCandidateGraph($candidate);
            $locked = $graph['candidate'];
            $previousRejectionReason = $locked->rejection_reason;

            CandidateWorkflowAuthorization::assertCanReopenRejected($actor, $locked);
            CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                array_key_exists('lock_version', $guard) ? (int) $guard['lock_version'] : null,
                $guard['expected_stage'] ?? CandidateStage::Rejected->value,
                null,
            );

            if ($locked->stage !== CandidateStage::Rejected) {
                throw ValidationException::withMessages([
                    'stage' => 'Only rejected candidates can be reopened.',
                ]);
            }

            $restoreStage = CandidateStage::tryFrom((string) ($locked->pre_rejection_stage ?? ''))
                ?? CandidateStage::Applied;

            if ($restoreStage === CandidateStage::Rejected) {
                $restoreStage = CandidateStage::Applied;
            }

            // Offer/JOL reopen must not clear Selected outcome or invent a Joining state.
            if ($restoreStage === CandidateStage::Joining) {
                $restoreStage = CandidateStage::OfferJol;
            }

            $fromOutcome = $locked->interview_outcome;
            $restoreOutcome = $restoreStage->isOfferWorkflowStage()
                ? CandidateInterviewOutcome::Selected
                : null;

            $locked->fill([
                'stage' => $restoreStage,
                'interview_outcome' => $restoreOutcome,
                'rejection_reason' => null,
                'pre_rejection_stage' => null,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ]);
            $locked->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::ReopenRejected,
                CandidateStage::Rejected,
                $restoreStage,
                $fromOutcome,
                $restoreOutcome,
                (int) $actor->id,
                $reason,
                [
                    'previous_rejection_reason' => $previousRejectionReason,
                    'previous_outcome' => $fromOutcome?->value,
                    'restored_stage' => $restoreStage->value,
                    'restored_outcome' => $restoreOutcome?->value,
                    'offer_workflow' => $restoreStage->isOfferWorkflowStage(),
                ],
            );

            return $locked->refresh();
        });
    }
}
