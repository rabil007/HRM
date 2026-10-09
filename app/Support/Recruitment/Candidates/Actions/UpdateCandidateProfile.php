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
        CandidateWorkflowAuthorization::assertExpectedLock(
            $candidate,
            isset($data['lock_version']) ? (int) $data['lock_version'] : null,
            null,
            null,
        );

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

        return DB::transaction(function () use ($actor, $candidate, $data, $email, $phone): RecruitmentCandidate {
            /** @var RecruitmentCandidate $locked */
            $locked = RecruitmentCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                isset($data['lock_version']) ? (int) $data['lock_version'] : null,
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

            $storage = new CandidateCvStorage;
            $removeCv = (bool) ($data['remove_cv'] ?? false);

            if ($removeCv && $locked->cv_path) {
                $storage->deleteStored($locked->cv_path, (int) $locked->company_id, (int) $locked->id);
                $locked->forceFill([
                    'cv_path' => null,
                    'cv_original_file_name' => null,
                    'cv_mime_type' => null,
                    'cv_file_size_bytes' => null,
                    'cv_file_checksum' => null,
                ])->save();
            }

            if (($data['cv'] ?? null) instanceof UploadedFile) {
                if ($locked->cv_path) {
                    $storage->deleteStored($locked->cv_path, (int) $locked->company_id, (int) $locked->id);
                }
                $locked->forceFill($storage->store($locked, $data['cv']))->save();
            }

            return $locked->refresh();
        });
    }
}
