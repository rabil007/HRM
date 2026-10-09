<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateSource;
use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidateContactNormalizer;
use App\Support\Recruitment\Candidates\CandidateCvStorage;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UpdateCandidateProfile
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
     *     remove_cv?: bool,
     *     lock_version?: int|null,
     * }  $data
     */
    public function handle(User $actor, RecruitmentCandidate $candidate, array $data): RecruitmentCandidate
    {
        CandidateWorkflowAuthorization::assertCanUpdate($actor, $candidate);

        $email = array_key_exists('email', $data)
            ? (trim((string) ($data['email'] ?? '')) ?: null)
            : $candidate->email;
        $phone = array_key_exists('phone', $data)
            ? (trim((string) ($data['phone'] ?? '')) ?: null)
            : $candidate->phone;

        if ($email === null && $phone === null) {
            throw ValidationException::withMessages([
                'email' => 'Provide at least one contact method (email or phone).',
            ]);
        }

        $storage = new CandidateCvStorage;
        $newCvPath = null;
        $companyId = (int) $candidate->company_id;
        $candidateId = (int) $candidate->id;

        try {
            return DB::transaction(function () use (
                $actor,
                $candidate,
                $data,
                $email,
                $phone,
                $storage,
                &$newCvPath,
                &$companyId,
                &$candidateId,
            ): RecruitmentCandidate {
                $graph = CandidateWorkflowAuthorization::lockCandidateGraph($candidate);
                $locked = $graph['candidate'];
                $companyId = (int) $locked->company_id;
                $candidateId = (int) $locked->id;

                CandidateWorkflowAuthorization::assertCanUpdate($actor, $locked);
                CandidateWorkflowAuthorization::assertExpectedLock(
                    $locked,
                    array_key_exists('lock_version', $data) ? (int) $data['lock_version'] : null,
                    null,
                    null,
                );

                $locked->fill([
                    'name' => trim((string) $data['name']),
                    'email' => $email,
                    'phone' => $phone,
                    'email_normalized' => CandidateContactNormalizer::email($email),
                    'phone_normalized' => CandidateContactNormalizer::phone($phone),
                    'nationality_id' => $data['nationality_id'] ?? null,
                    'source' => isset($data['source']) && $data['source'] !== '' && $data['source'] !== null
                        ? CandidateSource::from((string) $data['source'])
                        : null,
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                        ? trim((string) $data['notes'])
                        : null,
                    'updated_by' => $actor->id,
                    'lock_version' => (int) $locked->lock_version + 1,
                ]);
                $locked->save();

                $removeCv = (bool) ($data['remove_cv'] ?? false);
                $hasNewCv = ($data['cv'] ?? null) instanceof UploadedFile;
                $previousCvPath = $locked->cv_path;
                $pathToDeleteAfterCommit = null;

                if ($hasNewCv) {
                    $meta = $storage->store($locked, $data['cv']);
                    $newCvPath = $meta['cv_path'];
                    $locked->forceFill($meta)->save();

                    if (is_string($previousCvPath) && $previousCvPath !== '') {
                        $pathToDeleteAfterCommit = $previousCvPath;
                    }
                } elseif ($removeCv && is_string($previousCvPath) && $previousCvPath !== '') {
                    $locked->forceFill([
                        'cv_path' => null,
                        'cv_original_file_name' => null,
                        'cv_mime_type' => null,
                        'cv_file_size_bytes' => null,
                        'cv_file_checksum' => null,
                    ])->save();
                    $pathToDeleteAfterCommit = $previousCvPath;
                }

                if (is_string($pathToDeleteAfterCommit) && $pathToDeleteAfterCommit !== '') {
                    $deleteCompanyId = (int) $locked->company_id;
                    $deleteCandidateId = (int) $locked->id;
                    DB::afterCommit(function () use ($storage, $pathToDeleteAfterCommit, $deleteCompanyId, $deleteCandidateId): void {
                        $storage->deleteStored($pathToDeleteAfterCommit, $deleteCompanyId, $deleteCandidateId);
                    });
                }

                return $locked->refresh();
            });
        } catch (Throwable $exception) {
            if (is_string($newCvPath) && $newCvPath !== '') {
                $storage->deleteStored($newCvPath, $companyId, $candidateId);
            }

            throw $exception;
        }
    }
}
