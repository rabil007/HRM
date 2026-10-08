<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementDeadlineExtensionInitiator;
use App\Enums\Recruitment\RequirementHeadcountRevisionInitiator;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use App\Models\RecruitmentRequirementHeadcountRevision;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ValidateRequirementOwnershipTransferPendingRequests
{
    private const PENDING_INITIATOR_CANNOT_APPROVE_MESSAGE = 'The selected user initiated a pending request and cannot become its approver.';

    public static function assertEligible(
        RecruitmentRequirement $requirement,
        int $newRequesterId,
        ?int $newRecruiterId,
        bool $requesterChanged,
        bool $recruiterChanged,
    ): void {
        if (! $requesterChanged && ! $recruiterChanged) {
            return;
        }

        $companyId = (int) $requirement->company_id;
        $requirementId = (int) $requirement->id;

        /** @var Collection<int, RecruitmentRequirementDeadlineExtension> $pendingExtensions */
        $pendingExtensions = RecruitmentRequirementDeadlineExtension::query()
            ->where('recruitment_requirement_id', $requirementId)
            ->where('company_id', $companyId)
            ->pending()
            ->lockForUpdate()
            ->get();

        /** @var Collection<int, RecruitmentRequirementHeadcountRevision> $pendingRevisions */
        $pendingRevisions = RecruitmentRequirementHeadcountRevision::query()
            ->where('recruitment_requirement_id', $requirementId)
            ->where('company_id', $companyId)
            ->pending()
            ->lockForUpdate()
            ->get();

        foreach ($pendingExtensions as $extension) {
            if (
                $requesterChanged
                && $extension->initiator === RequirementDeadlineExtensionInitiator::Recruiter
                && (int) $extension->requested_by === $newRequesterId
            ) {
                throw ValidationException::withMessages([
                    'created_by' => self::PENDING_INITIATOR_CANNOT_APPROVE_MESSAGE,
                ]);
            }
        }

        foreach ($pendingRevisions as $revision) {
            if (
                $requesterChanged
                && $revision->initiator === RequirementHeadcountRevisionInitiator::Recruiter
                && (int) $revision->requested_by === $newRequesterId
            ) {
                throw ValidationException::withMessages([
                    'created_by' => self::PENDING_INITIATOR_CANNOT_APPROVE_MESSAGE,
                ]);
            }

            if (
                $recruiterChanged
                && $newRecruiterId !== null
                && $revision->initiator === RequirementHeadcountRevisionInitiator::Requester
                && (int) $revision->requested_by === $newRecruiterId
            ) {
                throw ValidationException::withMessages([
                    'assigned_to' => self::PENDING_INITIATOR_CANNOT_APPROVE_MESSAGE,
                ]);
            }
        }
    }
}
