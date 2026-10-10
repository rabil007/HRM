<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateOfferStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidateOfferDateValidation;
use App\Support\Recruitment\Candidates\CandidateOfferStorage;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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

        $newlyStoredFiles = [];

        try {
            return DB::transaction(function () use ($actor, $candidate, $offer, $data, &$newlyStoredFiles): RecruitmentCandidateOffer {
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

                $acceptedAt = CandidateOfferDateValidation::resolveAndValidateAcceptedAt(
                    $locked->company_id,
                    $lockedOffer,
                    $data['accepted_at'] ?? null,
                );

                $pendingDeletes = [];
                $companyId = (int) $locked->company_id;
                $candidateId = (int) $locked->id;
                $offerId = (int) $lockedOffer->id;

                $acceptanceDocument = $data['acceptance_document'] ?? null;
                if ($acceptanceDocument instanceof UploadedFile) {
                    if ($lockedOffer->acceptance_document_path) {
                        $pendingDeletes[] = $lockedOffer->acceptance_document_path;
                    }
                    $stored = $this->storage->store($locked, $lockedOffer, $acceptanceDocument, 'acceptance');
                    $newlyStoredFiles[] = [
                        'path' => $stored['path'],
                        'company_id' => $companyId,
                        'candidate_id' => $candidateId,
                        'offer_id' => $offerId,
                    ];
                    $lockedOffer->fill([
                        'acceptance_document_path' => $stored['path'],
                        'acceptance_document_original_file_name' => $stored['original_file_name'],
                        'acceptance_document_mime_type' => $stored['mime_type'],
                        'acceptance_document_file_size_bytes' => $stored['file_size_bytes'],
                        'acceptance_document_file_checksum' => $stored['file_checksum'],
                    ]);
                }

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

                DB::afterCommit(function () use ($pendingDeletes, $companyId, $candidateId, $offerId): void {
                    foreach ($pendingDeletes as $path) {
                        app(CandidateOfferStorage::class)->deleteStored($path, $companyId, $candidateId, $offerId);
                    }
                });

                return $lockedOffer->refresh();
            });
        } catch (Throwable $exception) {
            foreach ($newlyStoredFiles as $item) {
                $this->storage->deleteStored(
                    $item['path'],
                    $item['company_id'],
                    $item['candidate_id'],
                    $item['offer_id'],
                );
            }

            throw $exception;
        }
    }
}
