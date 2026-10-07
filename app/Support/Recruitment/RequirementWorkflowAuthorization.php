<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Record-relationship workflow guards.
 *
 * Permissions still apply, but they do not replace created_by / assigned_to ownership.
 */
final class RequirementWorkflowAuthorization
{
    public static function isCreator(User $user, RecruitmentRequirement $requirement): bool
    {
        return $requirement->created_by !== null
            && (int) $requirement->created_by === (int) $user->id;
    }

    public static function isAssignedRecruiter(User $user, RecruitmentRequirement $requirement): bool
    {
        return $requirement->assigned_to !== null
            && (int) $requirement->assigned_to === (int) $user->id;
    }

    public static function blocksSelfApproval(RecruitmentRequirement $requirement): bool
    {
        return $requirement->created_by !== null
            && $requirement->assigned_to !== null
            && (int) $requirement->created_by === (int) $requirement->assigned_to;
    }

    public static function canPrepare(User $user, RecruitmentRequirement $requirement): bool
    {
        return $user->can('recruitment.requirements.update')
            && self::isCreator($user, $requirement)
            && $requirement->status->isEditable();
    }

    public static function canSubmit(User $user, RecruitmentRequirement $requirement): bool
    {
        return $user->can('recruitment.requirements.submit')
            && self::isCreator($user, $requirement)
            && in_array($requirement->status, [RequirementStatus::Draft, RequirementStatus::Returned], true)
            && ! self::blocksSelfApproval($requirement);
    }

    public static function canApprove(User $user, RecruitmentRequirement $requirement): bool
    {
        return $user->can('recruitment.requirements.approve')
            && self::isAssignedRecruiter($user, $requirement)
            && $requirement->status === RequirementStatus::PendingApproval
            && ! self::blocksSelfApproval($requirement);
    }

    public static function canReturn(User $user, RecruitmentRequirement $requirement): bool
    {
        return $user->can('recruitment.requirements.approve')
            && self::isAssignedRecruiter($user, $requirement)
            && $requirement->status === RequirementStatus::PendingApproval;
    }

    public static function assertCanPrepare(User $user, RecruitmentRequirement $requirement): void
    {
        if (! $user->can('recruitment.requirements.update')) {
            throw ValidationException::withMessages([
                'status' => 'You do not have permission to update requirements.',
            ]);
        }

        if (! $requirement->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => "Requirement cannot be edited from {$requirement->status->label()} status.",
            ]);
        }

        if (! self::isCreator($user, $requirement)) {
            throw ValidationException::withMessages([
                'status' => 'Only the requirement requester can prepare this Draft or Returned requirement.',
            ]);
        }
    }

    public static function assertCanSubmit(User $user, RecruitmentRequirement $requirement): void
    {
        if (! $user->can('recruitment.requirements.submit')) {
            throw ValidationException::withMessages([
                'status' => 'You do not have permission to submit requirements for approval.',
            ]);
        }

        if (! in_array($requirement->status, [RequirementStatus::Draft, RequirementStatus::Returned], true)) {
            throw ValidationException::withMessages([
                'status' => "Requirement cannot be submitted from {$requirement->status->label()} status.",
            ]);
        }

        if (! self::isCreator($user, $requirement)) {
            throw ValidationException::withMessages([
                'status' => 'Only the requirement requester can submit or resubmit this requirement.',
            ]);
        }

        if (self::blocksSelfApproval($requirement)) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The requester cannot also be the assigned recruiter. Self-approval is not allowed.',
            ]);
        }
    }
}
