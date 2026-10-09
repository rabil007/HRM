<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateOfferStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RejectCandidateOffer
{
    /**
     * @param  array{
     *     rejected_at?: string|null,
     *     reason: string,
     *     lock_version?: int|null,
     *     offer_lock_version?: int|null,
     *     expected_stage?: string|null,
     *     expected_offer_status?: string|null,
     * }  $data
     */
    public function handle(
        User $actor,
        RecruitmentCandidate $candidate,
        RecruitmentCandidateOffer $offer,
        array $data,
    ): RecruitmentCandidateOffer {
        $candidate->loadMissing('requirement', 'currentOffer');
        CandidateWorkflowAuthorization::assertCanDecideOffer($actor, $candidate);

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A rejection reason is required when declining an offer.',
            ]);
        }

        return DB::transaction(function () use ($actor, $candidate, $offer, $data, $reason): RecruitmentCandidateOffer {
            $graph = CandidateWorkflowAuthorization::lockCandidateOfferGraph($candidate);
            $locked = $graph['candidate'];
            $lockedOffer = $graph['offer'];

            CandidateWorkflowAuthorization::assertCanDecideOffer($actor, $locked);
            CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                array_key_exists('lock_version', $data) ? (int) $data['lock_version'] : null,
                $data['expected_stage'] ?? CandidateStage::OfferJol->value,
                null,
            );

            if ($lockedOffer === null || (int) $lockedOffer->id !== (int) $offer->id) {
                throw ValidationException::withMessages([
                    'offer' => 'This offer is no longer the current offer. Refresh and try again.',
                ]);
            }

            CandidateWorkflowAuthorization::assertOfferExpectedLock(
                $lockedOffer,
                array_key_exists('offer_lock_version', $data) ? (int) $data['offer_lock_version'] : null,
                $data['expected_offer_status'] ?? CandidateOfferStatus::Sent->value,
            );

            if (! CandidateWorkflowAuthorization::canDecideOffer($lockedOffer, $locked)) {
                throw ValidationException::withMessages([
                    'offer_status' => 'Only Sent offers in Offer/JOL can be rejected.',
                ]);
            }

            $rejectedAt = filled($data['rejected_at'] ?? null)
                ? $data['rejected_at']
                : now();

            $fromStage = $locked->stage;
            $fromOutcome = $locked->interview_outcome;

            $lockedOffer->fill([
                'status' => CandidateOfferStatus::Rejected,
                'rejected_at' => $rejectedAt,
                'rejected_by' => $actor->id,
                'rejection_reason' => $reason,
                'updated_by' => $actor->id,
                'lock_version' => (int) $lockedOffer->lock_version + 1,
            ])->save();

            // Preserve interview_outcome (typically selected). Offer decline ≠ interview not_selected.
            $locked->fill([
                'pre_rejection_stage' => CandidateStage::OfferJol->value,
                'stage' => CandidateStage::Rejected,
                'rejection_reason' => $reason,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::OfferRejected,
                $fromStage,
                CandidateStage::Rejected,
                $fromOutcome,
                $fromOutcome,
                (int) $actor->id,
                $reason,
                [
                    'offer_id' => $lockedOffer->id,
                    'rejected_at' => optional($lockedOffer->rejected_at)?->toIso8601String(),
                    'rejection_kind' => 'offer_declined',
                ],
            );

            return $lockedOffer->refresh();
        });
    }
}
