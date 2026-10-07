<?php

namespace App\Actions\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Models\User;
use App\Support\Recruitment\RequirementAttachmentStorage;
use App\Support\Recruitment\SendRequirementLifecycleEmails;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Atomic update + submit/resubmit for Draft or Returned requirements.
 *
 * Avoids a fragile frontend PUT-then-POST chain.
 * Lifecycle email is dispatched only after the outer transaction commits.
 * Newly stored attachment files are deleted if the outer operation fails.
 */
final class SaveAndSubmitRequirementAction
{
    public function __construct(
        private UpdateRequirementAction $updateAction,
        private SubmitRequirementForApprovalAction $submitAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(
        RecruitmentRequirement $requirement,
        User $actor,
        array $data,
        ?UploadedFile $attachment = null,
    ): RecruitmentRequirement {
        $newStoredFilePath = null;

        try {
            $result = DB::transaction(function () use ($requirement, $actor, $data, $attachment, &$newStoredFilePath): array {
                $updated = $this->updateAction->execute(
                    $requirement,
                    (int) $actor->id,
                    $data,
                    $attachment,
                    $newStoredFilePath,
                );

                $submitted = $this->submitAction->execute($updated, $actor, dispatchNotifications: false);

                $transition = RecruitmentRequirementStatusTransition::query()
                    ->where('company_id', (int) $submitted->company_id)
                    ->where('recruitment_requirement_id', (int) $submitted->id)
                    ->where('to_status', $submitted->status->value)
                    ->orderByDesc('id')
                    ->first();

                return [$submitted, $transition];
            });
        } catch (Throwable $exception) {
            if ($newStoredFilePath !== null) {
                Storage::disk(RequirementAttachmentStorage::DISK)->delete($newStoredFilePath);
            }

            throw $exception;
        }

        /** @var RecruitmentRequirement $submitted */
        [$submitted, $transition] = $result;

        if ($transition instanceof RecruitmentRequirementStatusTransition) {
            SendRequirementLifecycleEmails::submittedForApproval($submitted, $transition);
        }

        return $submitted;
    }
}
