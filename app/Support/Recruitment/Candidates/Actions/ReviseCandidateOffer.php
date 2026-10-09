<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateInterviewOutcome;
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

final class ReviseCandidateOffer
{
    /**
     * @param  array{
     *     reason: string,
     *     salary_amount?: float|string|null,
     *     salary_currency_code?: string|null,
     *     proposed_joining_date?: string|null,
     *     offer_date?: string|null,
     *     expiry_date?: string|null,
     *     notes?: string|null,
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
        CandidateWorkflowAuthorization::assertCanReviseOffer($actor, $candidate);

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A revision reason is required.',
            ]);
        }

        return DB::transaction(function () use ($actor, $candidate, $offer, $data, $reason): RecruitmentCandidateOffer {
            $graph = CandidateWorkflowAuthorization::lockCandidateOfferGraph($candidate);
            $locked = $graph['candidate'];
            $lockedOffer = $graph['offer'];

            CandidateWorkflowAuthorization::assertCanReviseOffer($actor, $locked);
            CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                array_key_exists('lock_version', $data) ? (int) $data['lock_version'] : null,
                $data['expected_stage'] ?? null,
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
                $data['expected_offer_status'] ?? $lockedOffer->status->value,
            );

            if (! CandidateWorkflowAuthorization::canReviseOffer($lockedOffer, $locked)) {
                throw ValidationException::withMessages([
                    'offer_status' => 'Only Sent, Accepted, or Rejected current offers can be revised, and the candidate must not be in Rejected stage.',
                ]);
            }

            $previousStatus = $lockedOffer->status;
            $lockedOffer->fill([
                'is_current' => false,
                'updated_by' => $actor->id,
                'lock_version' => (int) $lockedOffer->lock_version + 1,
            ])->save();

            $revisionNumber = (int) $lockedOffer->revision_number + 1;
            $newOffer = RecruitmentCandidateOffer::query()->create([
                'company_id' => $locked->company_id,
                'recruitment_candidate_id' => $locked->id,
                'revision_number' => $revisionNumber,
                'is_current' => true,
                'supersedes_offer_id' => $lockedOffer->id,
                'status' => CandidateOfferStatus::Draft,
                'salary_amount' => $data['salary_amount'] ?? $lockedOffer->salary_amount,
                'salary_currency_code' => strtoupper((string) ($data['salary_currency_code'] ?? $lockedOffer->salary_currency_code)),
                'proposed_joining_date' => $data['proposed_joining_date'] ?? $lockedOffer->proposed_joining_date?->toDateString(),
                'offer_date' => $data['offer_date'] ?? $lockedOffer->offer_date?->toDateString(),
                'expiry_date' => array_key_exists('expiry_date', $data)
                    ? ($data['expiry_date'] ?: null)
                    : $lockedOffer->expiry_date?->toDateString(),
                'notes' => array_key_exists('notes', $data)
                    ? (filled($data['notes']) ? trim((string) $data['notes']) : null)
                    : $lockedOffer->notes,
                'revision_reason' => $reason,
                'lock_version' => 0,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $fromStage = $locked->stage;
            $locked->fill([
                'stage' => CandidateStage::OfferJol,
                'interview_outcome' => CandidateInterviewOutcome::Selected,
                'rejection_reason' => null,
                'pre_rejection_stage' => null,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::OfferRevised,
                $fromStage,
                CandidateStage::OfferJol,
                $locked->interview_outcome,
                CandidateInterviewOutcome::Selected,
                (int) $actor->id,
                $reason,
                [
                    'previous_offer_id' => $lockedOffer->id,
                    'previous_status' => $previousStatus->value,
                    'new_offer_id' => $newOffer->id,
                    'revision_number' => $revisionNumber,
                ],
            );

            return $newOffer->refresh();
        });
    }
}
