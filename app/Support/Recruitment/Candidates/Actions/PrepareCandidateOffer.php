<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateInterviewOutcome;
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

final class PrepareCandidateOffer
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
     *     lock_version?: int|null,
     *     expected_stage?: string|null,
     *     expected_outcome?: string|null,
     * }  $data
     */
    public function handle(User $actor, RecruitmentCandidate $candidate, array $data): RecruitmentCandidateOffer
    {
        $candidate->loadMissing('requirement', 'line', 'currentOffer');
        CandidateWorkflowAuthorization::assertCanPrepareOffer($actor, $candidate);

        $newlyStoredFiles = [];

        try {
            return DB::transaction(function () use ($actor, $candidate, $data, &$newlyStoredFiles): RecruitmentCandidateOffer {
                $graph = CandidateWorkflowAuthorization::lockCandidateOfferGraph($candidate);
                $locked = $graph['candidate'];
                $line = $graph['line'];

                CandidateWorkflowAuthorization::assertCanPrepareOffer($actor, $locked);
                CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
                CandidateWorkflowAuthorization::assertExpectedLock(
                    $locked,
                    array_key_exists('lock_version', $data) ? (int) $data['lock_version'] : null,
                    $data['expected_stage'] ?? CandidateStage::Interview->value,
                    $data['expected_outcome'] ?? CandidateInterviewOutcome::Selected->value,
                );

                if (! CandidateWorkflowAuthorization::canPrepareOffer($locked)) {
                    throw ValidationException::withMessages([
                        'candidate' => 'Only Selected Interview candidates without a current offer can prepare an Offer/JOL.',
                    ]);
                }

                if ($graph['offer'] !== null) {
                    throw ValidationException::withMessages([
                        'candidate' => 'This candidate already has a current offer. Use revise to create a new revision.',
                    ]);
                }

                CandidateOfferDateValidation::validateOfferDraftDates(
                    $locked->company_id,
                    $data['offer_date'] ?? null,
                    $data['proposed_joining_date'] ?? null,
                    $data['expiry_date'] ?? null,
                );

                $offer = RecruitmentCandidateOffer::query()->create([
                    'company_id' => $locked->company_id,
                    'recruitment_candidate_id' => $locked->id,
                    'revision_number' => 1,
                    'is_current' => true,
                    'supersedes_offer_id' => null,
                    'status' => CandidateOfferStatus::Draft,
                    'salary_amount' => $data['salary_amount'],
                    'salary_currency_code' => strtoupper((string) $data['salary_currency_code']),
                    'proposed_joining_date' => $data['proposed_joining_date'],
                    'offer_date' => $data['offer_date'],
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                    'lock_version' => 0,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $document = $data['offer_document'] ?? null;
                if ($document instanceof UploadedFile) {
                    $stored = $this->storage->store($locked, $offer, $document, 'offer');
                    $newlyStoredFiles[] = [
                        'path' => $stored['path'],
                        'company_id' => (int) $locked->company_id,
                        'candidate_id' => (int) $locked->id,
                        'offer_id' => (int) $offer->id,
                    ];
                    $offer->fill([
                        'offer_document_path' => $stored['path'],
                        'offer_document_original_file_name' => $stored['original_file_name'],
                        'offer_document_mime_type' => $stored['mime_type'],
                        'offer_document_file_size_bytes' => $stored['file_size_bytes'],
                        'offer_document_file_checksum' => $stored['file_checksum'],
                    ])->save();
                }

                $fromStage = $locked->stage;
                $locked->fill([
                    'stage' => CandidateStage::OfferJol,
                    'updated_by' => $actor->id,
                    'lock_version' => (int) $locked->lock_version + 1,
                ]);
                $locked->save();

                RecordCandidateStageTransition::handle(
                    $locked,
                    CandidateTransitionAction::OfferPrepared,
                    $fromStage,
                    CandidateStage::OfferJol,
                    CandidateInterviewOutcome::Selected,
                    CandidateInterviewOutcome::Selected,
                    (int) $actor->id,
                    null,
                    [
                        'offer_id' => $offer->id,
                        'revision_number' => 1,
                        'salary_amount' => (string) $offer->salary_amount,
                        'salary_currency_code' => $offer->salary_currency_code,
                        'position_salary_min' => $line?->salary_min,
                        'position_salary_max' => $line?->salary_max,
                        'position_salary_currency_code' => $line?->salary_currency_code,
                    ],
                );

                return $offer->refresh();
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
