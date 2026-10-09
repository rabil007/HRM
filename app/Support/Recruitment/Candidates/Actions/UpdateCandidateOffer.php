<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateOfferStatus;
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

final class UpdateCandidateOffer
{
    public function __construct(private readonly CandidateOfferStorage $storage) {}

    /**
     * @param  array{
     *     salary_amount: float|string,
     *     salary_currency_code: string,
     *     proposed_joining_date: string,
     *     offer_date: string,
     *     expiry_date?: string|null,
     *     notes?: string|null,
     *     offer_document?: UploadedFile|null,
     *     remove_offer_document?: bool,
     *     acceptance_document?: UploadedFile|null,
     *     remove_acceptance_document?: bool,
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
        $candidate->loadMissing('requirement', 'line', 'currentOffer');
        CandidateWorkflowAuthorization::assertCanUpdateOffer($actor, $candidate);

        return DB::transaction(function () use ($actor, $candidate, $offer, $data): RecruitmentCandidateOffer {
            $graph = CandidateWorkflowAuthorization::lockCandidateOfferGraph($candidate);
            $locked = $graph['candidate'];
            $lockedOffer = $graph['offer'];

            CandidateWorkflowAuthorization::assertCanUpdateOffer($actor, $locked);
            CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                array_key_exists('lock_version', $data) ? (int) $data['lock_version'] : null,
                $data['expected_stage'] ?? null,
                null,
            );

            if ($lockedOffer === null || (int) $lockedOffer->id !== (int) $offer->id || ! $lockedOffer->is_current) {
                throw ValidationException::withMessages([
                    'offer' => 'This offer is no longer the current offer. Refresh and try again.',
                ]);
            }

            CandidateWorkflowAuthorization::assertOfferExpectedLock(
                $lockedOffer,
                array_key_exists('offer_lock_version', $data) ? (int) $data['offer_lock_version'] : null,
                $data['expected_offer_status'] ?? CandidateOfferStatus::Draft->value,
            );

            if (! CandidateWorkflowAuthorization::canUpdateOffer($lockedOffer)) {
                throw ValidationException::withMessages([
                    'offer_status' => 'Only Draft offers can be edited. Use revise for sent or decided offers.',
                ]);
            }

            $pendingDeletes = [];
            $companyId = (int) $locked->company_id;
            $candidateId = (int) $locked->id;
            $offerId = (int) $lockedOffer->id;

            $lockedOffer->fill([
                'salary_amount' => $data['salary_amount'],
                'salary_currency_code' => strtoupper((string) $data['salary_currency_code']),
                'proposed_joining_date' => $data['proposed_joining_date'],
                'offer_date' => $data['offer_date'],
                'expiry_date' => $data['expiry_date'] ?? null,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'updated_by' => $actor->id,
                'lock_version' => (int) $lockedOffer->lock_version + 1,
            ]);

            $offerDocument = $data['offer_document'] ?? null;
            if ($offerDocument instanceof UploadedFile) {
                if ($lockedOffer->offer_document_path) {
                    $pendingDeletes[] = $lockedOffer->offer_document_path;
                }
                $stored = $this->storage->store($locked, $lockedOffer, $offerDocument, 'offer');
                $lockedOffer->fill([
                    'offer_document_path' => $stored['path'],
                    'offer_document_original_file_name' => $stored['original_file_name'],
                    'offer_document_mime_type' => $stored['mime_type'],
                    'offer_document_file_size_bytes' => $stored['file_size_bytes'],
                    'offer_document_file_checksum' => $stored['file_checksum'],
                ]);
            } elseif (! empty($data['remove_offer_document'])) {
                if ($lockedOffer->offer_document_path) {
                    $pendingDeletes[] = $lockedOffer->offer_document_path;
                }
                $lockedOffer->fill([
                    'offer_document_path' => null,
                    'offer_document_original_file_name' => null,
                    'offer_document_mime_type' => null,
                    'offer_document_file_size_bytes' => null,
                    'offer_document_file_checksum' => null,
                ]);
            }

            $acceptanceDocument = $data['acceptance_document'] ?? null;
            if ($acceptanceDocument instanceof UploadedFile) {
                if ($lockedOffer->acceptance_document_path) {
                    $pendingDeletes[] = $lockedOffer->acceptance_document_path;
                }
                $stored = $this->storage->store($locked, $lockedOffer, $acceptanceDocument, 'acceptance');
                $lockedOffer->fill([
                    'acceptance_document_path' => $stored['path'],
                    'acceptance_document_original_file_name' => $stored['original_file_name'],
                    'acceptance_document_mime_type' => $stored['mime_type'],
                    'acceptance_document_file_size_bytes' => $stored['file_size_bytes'],
                    'acceptance_document_file_checksum' => $stored['file_checksum'],
                ]);
            } elseif (! empty($data['remove_acceptance_document'])) {
                if ($lockedOffer->acceptance_document_path) {
                    $pendingDeletes[] = $lockedOffer->acceptance_document_path;
                }
                $lockedOffer->fill([
                    'acceptance_document_path' => null,
                    'acceptance_document_original_file_name' => null,
                    'acceptance_document_mime_type' => null,
                    'acceptance_document_file_size_bytes' => null,
                    'acceptance_document_file_checksum' => null,
                ]);
            }

            $lockedOffer->save();

            $locked->fill([
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::OfferUpdated,
                $locked->stage,
                $locked->stage,
                $locked->interview_outcome,
                $locked->interview_outcome,
                (int) $actor->id,
                null,
                [
                    'offer_id' => $lockedOffer->id,
                    'revision_number' => $lockedOffer->revision_number,
                    'status' => $lockedOffer->status->value,
                ],
            );

            DB::afterCommit(function () use ($pendingDeletes, $companyId, $candidateId, $offerId): void {
                foreach ($pendingDeletes as $path) {
                    app(CandidateOfferStorage::class)->deleteStored($path, $companyId, $candidateId, $offerId);
                }
            });

            return $lockedOffer->refresh();
        });
    }
}
