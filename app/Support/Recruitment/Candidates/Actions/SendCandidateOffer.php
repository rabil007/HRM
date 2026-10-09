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

final class SendCandidateOffer
{
    /**
     * @param  array{
     *     sent_at?: string|null,
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
        array $data = [],
    ): RecruitmentCandidateOffer {
        $candidate->loadMissing('requirement', 'currentOffer');
        CandidateWorkflowAuthorization::assertCanSendOffer($actor, $candidate);

        return DB::transaction(function () use ($actor, $candidate, $offer, $data): RecruitmentCandidateOffer {
            $graph = CandidateWorkflowAuthorization::lockCandidateOfferGraph($candidate);
            $locked = $graph['candidate'];
            $lockedOffer = $graph['offer'];

            CandidateWorkflowAuthorization::assertCanSendOffer($actor, $locked);
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
                $data['expected_offer_status'] ?? CandidateOfferStatus::Draft->value,
            );

            if (! CandidateWorkflowAuthorization::canSendOffer($lockedOffer)) {
                throw ValidationException::withMessages([
                    'offer_status' => 'Only Draft offers can be marked as sent.',
                ]);
            }

            $sentAt = filled($data['sent_at'] ?? null)
                ? $data['sent_at']
                : now();

            $lockedOffer->fill([
                'status' => CandidateOfferStatus::Sent,
                'sent_at' => $sentAt,
                'sent_by' => $actor->id,
                'updated_by' => $actor->id,
                'lock_version' => (int) $lockedOffer->lock_version + 1,
            ])->save();

            $locked->fill([
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::OfferSent,
                $locked->stage,
                $locked->stage,
                $locked->interview_outcome,
                $locked->interview_outcome,
                (int) $actor->id,
                null,
                [
                    'offer_id' => $lockedOffer->id,
                    'sent_at' => optional($lockedOffer->sent_at)?->toIso8601String(),
                    'note' => 'Offer recorded as sent outside the system; no email was sent.',
                ],
            );

            return $lockedOffer->refresh();
        });
    }
}
