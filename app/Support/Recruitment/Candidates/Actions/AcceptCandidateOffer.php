<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateOfferStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidateOfferStorage;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcceptCandidateOffer
{
    public function __construct(private readonly CandidateOfferStorage $storage) {}

    /**
     * @param  array{
     *     accepted_at?: string|null,
     *     acceptance_document?: UploadedFile|null,
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
        CandidateWorkflowAuthorization::assertCanDecideOffer($actor, $candidate);

        return DB::transaction(function () use ($actor, $candidate, $offer, $data): RecruitmentCandidateOffer {
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
                    'offer_status' => 'Only Sent offers in Offer/JOL can be accepted.',
                ]);
            }

            $acceptanceDocument = $data['acceptance_document'] ?? null;
            if ($acceptanceDocument instanceof UploadedFile) {
                $stored = $this->storage->store($locked, $lockedOffer, $acceptanceDocument, 'acceptance');
                $lockedOffer->fill([
                    'acceptance_document_path' => $stored['path'],
                    'acceptance_document_original_file_name' => $stored['original_file_name'],
                    'acceptance_document_mime_type' => $stored['mime_type'],
                    'acceptance_document_file_size_bytes' => $stored['file_size_bytes'],
                    'acceptance_document_file_checksum' => $stored['file_checksum'],
                ]);
            }

            $acceptedAt = filled($data['accepted_at'] ?? null)
                ? $data['accepted_at']
                : now();

            $fromStage = $locked->stage;
            $lockedOffer->fill([
                'status' => CandidateOfferStatus::Accepted,
                'accepted_at' => $acceptedAt,
                'accepted_by' => $actor->id,
                'updated_by' => $actor->id,
                'lock_version' => (int) $lockedOffer->lock_version + 1,
            ])->save();

            $locked->fill([
                'stage' => CandidateStage::Joining,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::OfferAccepted,
                $fromStage,
                CandidateStage::Joining,
                $locked->interview_outcome,
                $locked->interview_outcome,
                (int) $actor->id,
                null,
                [
                    'offer_id' => $lockedOffer->id,
                    'accepted_at' => optional($lockedOffer->accepted_at)?->toIso8601String(),
                ],
            );

            return $lockedOffer->refresh();
        });
    }
}
