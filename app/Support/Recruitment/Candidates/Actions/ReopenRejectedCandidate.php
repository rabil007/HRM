<?php

namespace App\Support\Recruitment\Candidates\Actions;

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
        CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($candidate, (int) $candidate->company_id);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reopen reason is required.',
            ]);
        }

        return DB::transaction(function () use ($actor, $candidate, $reason, $guard): RecruitmentCandidate {
            /** @var RecruitmentCandidate $locked */
            $locked = RecruitmentCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                isset($guard['lock_version']) ? (int) $guard['lock_version'] : null,
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

            $fromOutcome = $locked->interview_outcome;

            $locked->fill([
                'stage' => $restoreStage,
                'interview_outcome' => null,
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
                null,
                (int) $actor->id,
                $reason,
                [
                    'previous_rejection_reason' => $candidate->rejection_reason,
                    'previous_outcome' => $fromOutcome?->value,
                    'restored_stage' => $restoreStage->value,
                ],
            );

            return $locked->refresh();
        });
    }
}
