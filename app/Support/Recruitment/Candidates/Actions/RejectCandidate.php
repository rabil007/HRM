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

final class RejectCandidate
{
    /**
     * @param  array{lock_version?: int|null, expected_stage?: string|null, expected_outcome?: string|null}  $guard
     */
    public function handle(
        User $actor,
        RecruitmentCandidate $candidate,
        string $reason,
        array $guard = [],
    ): RecruitmentCandidate {
        CandidateWorkflowAuthorization::assertCanMove($actor, $candidate);
        CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($candidate, (int) $candidate->company_id);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A rejection reason is required.',
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
                $guard['expected_stage'] ?? null,
                $guard['expected_outcome'] ?? null,
            );

            if (! $locked->stage->allowsRejection()) {
                throw ValidationException::withMessages([
                    'stage' => 'This candidate cannot be rejected from the current stage.',
                ]);
            }

            if ($locked->interview_outcome === CandidateInterviewOutcome::Selected) {
                throw ValidationException::withMessages([
                    'interview_outcome' => 'Undo selection before rejecting this candidate.',
                ]);
            }

            $fromStage = $locked->stage;
            $fromOutcome = $locked->interview_outcome;
            $toOutcome = $fromStage === CandidateStage::Interview
                ? CandidateInterviewOutcome::NotSelected
                : null;

            $locked->fill([
                'pre_rejection_stage' => $fromStage->value,
                'stage' => CandidateStage::Rejected,
                'interview_outcome' => $toOutcome,
                'rejection_reason' => $reason,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ]);
            $locked->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::Rejected,
                $fromStage,
                CandidateStage::Rejected,
                $fromOutcome,
                $toOutcome,
                (int) $actor->id,
                $reason,
            );

            return $locked->refresh();
        });
    }
}
