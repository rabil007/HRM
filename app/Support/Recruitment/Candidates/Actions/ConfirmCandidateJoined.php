<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateJoiningReadinessStatus;
use App\Enums\Recruitment\CandidateOfferStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidateOfferDateValidation;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmCandidateJoined
{
    /**
     * @param  array{
     *     actual_joining_date?: string|null,
     *     notes?: string|null,
     *     lock_version?: int|null,
     *     expected_stage?: string|null,
     * }  $data
     */
    public function handle(
        User $actor,
        RecruitmentCandidate $candidate,
        array $data = [],
    ): RecruitmentCandidate {
        $candidate->loadMissing('requirement', 'currentOffer');
        CandidateWorkflowAuthorization::assertCanConfirmJoined($actor, $candidate);

        return DB::transaction(function () use ($actor, $candidate, $data): RecruitmentCandidate {
            $graph = CandidateWorkflowAuthorization::lockCandidateOfferGraph($candidate);
            $locked = $graph['candidate'];
            $lockedOffer = $graph['offer'];

            CandidateWorkflowAuthorization::assertCanConfirmJoined($actor, $locked);
            CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                array_key_exists('lock_version', $data) ? (int) $data['lock_version'] : null,
                $data['expected_stage'] ?? CandidateStage::Joining->value,
                null,
            );

            if ($locked->stage === CandidateStage::Joined) {
                throw ValidationException::withMessages([
                    'candidate' => 'Candidate has already been confirmed as joined.',
                ]);
            }

            if ($locked->stage !== CandidateStage::Joining) {
                throw ValidationException::withMessages([
                    'stage' => 'Candidate must be in Joining stage to confirm joined.',
                ]);
            }

            if ($lockedOffer === null || $lockedOffer->status !== CandidateOfferStatus::Accepted) {
                throw ValidationException::withMessages([
                    'offer' => 'Candidate must have an accepted current Offer/JOL to confirm joined.',
                ]);
            }

            if ($locked->joining_readiness_status !== CandidateJoiningReadinessStatus::Ready) {
                throw ValidationException::withMessages([
                    'joining_readiness_status' => 'Candidate joining readiness must be Ready before confirming joined.',
                ]);
            }

            $dateResolution = CandidateOfferDateValidation::resolveAndValidateActualJoiningDate(
                $locked->company_id,
                $locked,
                $lockedOffer,
                $data['actual_joining_date'] ?? null,
            );

            $notes = array_key_exists('notes', $data) ? (trim((string) $data['notes']) ?: null) : null;
            $fromStage = $locked->stage;

            $locked->fill([
                'stage' => CandidateStage::Joined,
                'actual_joining_date' => $dateResolution['actual_joining_date'],
                'joined_at' => $dateResolution['joined_at'],
                'joined_by' => $actor->id,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::Joined,
                $fromStage,
                CandidateStage::Joined,
                $locked->interview_outcome,
                $locked->interview_outcome,
                (int) $actor->id,
                $notes,
                [
                    'actual_joining_date' => $dateResolution['actual_joining_date'],
                    'joined_at' => $dateResolution['joined_at']->toIso8601String(),
                    'offer_id' => $lockedOffer->id,
                ],
            );

            return $locked->refresh();
        });
    }
}
