<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateSource;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidateContactNormalizer;
use App\Support\Recruitment\Candidates\CandidateCvStorage;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreateCandidate
{
    /**
     * @param  array{
     *     name: string,
     *     email?: string|null,
     *     phone?: string|null,
     *     nationality_id?: int|null,
     *     source?: string|null,
     *     notes?: string|null,
     *     cv?: UploadedFile|null,
     * }  $data
     */
    public function handle(
        User $actor,
        int $companyId,
        RecruitmentRequirement $requirement,
        RecruitmentRequirementLine $line,
        array $data,
    ): RecruitmentCandidate {
        CandidateWorkflowAuthorization::assertCanCreate($actor, $requirement);
        CandidateWorkflowAuthorization::assertParentsAllowCreate($requirement, $line, $companyId);

        $email = isset($data['email']) ? trim((string) $data['email']) : null;
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : null;
        $email = $email === '' ? null : $email;
        $phone = $phone === '' ? null : $phone;

        if ($email === null && $phone === null) {
            throw ValidationException::withMessages([
                'email' => 'Provide at least one contact method (email or phone).',
            ]);
        }

        $storage = new CandidateCvStorage;
        $newCvPath = null;
        $createdCandidateId = null;

        try {
            return DB::transaction(function () use (
                $actor,
                $companyId,
                $requirement,
                $line,
                $data,
                $email,
                $phone,
                $storage,
                &$newCvPath,
                &$createdCandidateId,
            ): RecruitmentCandidate {
                $parents = CandidateWorkflowAuthorization::lockParentsForCreate(
                    (int) $requirement->id,
                    (int) $line->id,
                );
                $lockedRequirement = $parents['requirement'];
                $lockedLine = $parents['line'];

                CandidateWorkflowAuthorization::assertCanCreate($actor, $lockedRequirement);
                CandidateWorkflowAuthorization::assertParentsAllowCreate(
                    $lockedRequirement,
                    $lockedLine,
                    $companyId,
                );

                $lockedLine->loadMissing('position:id,title');
                $positionTitle = (string) ($lockedLine->position?->title ?? 'Unknown position');

                $candidate = RecruitmentCandidate::query()->create([
                    'company_id' => $companyId,
                    'recruitment_requirement_id' => $lockedRequirement->id,
                    'recruitment_requirement_line_id' => $lockedLine->id,
                    'requirement_number_snapshot' => (string) $lockedRequirement->requirement_number,
                    'position_title_snapshot' => $positionTitle,
                    'name' => trim((string) $data['name']),
                    'email' => $email,
                    'phone' => $phone,
                    'email_normalized' => CandidateContactNormalizer::email($email),
                    'phone_normalized' => CandidateContactNormalizer::phone($phone),
                    'nationality_id' => $data['nationality_id'] ?? null,
                    'source' => isset($data['source']) && $data['source'] !== ''
                        ? CandidateSource::from((string) $data['source'])
                        : null,
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                        ? trim((string) $data['notes'])
                        : null,
                    'stage' => CandidateStage::Applied,
                    'interview_outcome' => null,
                    'lock_version' => 0,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $createdCandidateId = (int) $candidate->id;

                if (($data['cv'] ?? null) instanceof UploadedFile) {
                    $meta = $storage->store($candidate, $data['cv']);
                    $newCvPath = $meta['cv_path'];
                    $candidate->forceFill($meta)->save();
                }

                RecordCandidateStageTransition::handle(
                    $candidate,
                    CandidateTransitionAction::Created,
                    null,
                    CandidateStage::Applied,
                    null,
                    null,
                    (int) $actor->id,
                );

                return $candidate->refresh();
            });
        } catch (Throwable $exception) {
            if (is_string($newCvPath) && $newCvPath !== '' && $createdCandidateId !== null) {
                $storage->deleteStored($newCvPath, $companyId, $createdCandidateId);
            }

            throw $exception;
        }
    }
}
