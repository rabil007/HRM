<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;
use Illuminate\Support\Carbon;

/**
 * Immutable snapshot for lifecycle email jobs. Captured at dispatch time so queued
 * delivery does not depend on later mutable requirement fields. Email addresses are
 * intentionally omitted — resolve them only inside the job.
 *
 * @phpstan-type PayloadArray array{
 *     requirement_id: int,
 *     company_id: int,
 *     event: string,
 *     status_transition_id: int|null,
 *     primary_recipient_user_id: int|null,
 *     requester_user_id: int|null,
 *     submitter_user_id: int|null,
 *     additional_cc_user_ids: list<int>,
 *     actor_user_id: int|null,
 *     event_occurred_at: string,
 *     return_reason: string|null,
 *     expected_recruiter_id: int|null,
 *     expected_status: string|null,
 *     assignment_version: string|null
 * }
 */
final class RequirementLifecycleEmailPayload
{
    public const EVENT_SUBMITTED = 'submitted';

    public const EVENT_REASSIGNED = 'reassigned';

    public const EVENT_APPROVED = 'approved';

    public const EVENT_RETURNED = 'returned';

    /**
     * @return PayloadArray
     */
    public static function forSubmitted(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementStatusTransition $transition,
    ): array {
        return self::build($requirement, self::EVENT_SUBMITTED, $transition);
    }

    /**
     * @return PayloadArray
     */
    public static function forReassigned(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementStatusTransition $transition,
    ): array {
        return self::build($requirement, self::EVENT_REASSIGNED, $transition);
    }

    /**
     * @return PayloadArray
     */
    public static function forApproved(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementStatusTransition $transition,
    ): array {
        return self::build($requirement, self::EVENT_APPROVED, $transition);
    }

    /**
     * @return PayloadArray
     */
    public static function forReturned(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementStatusTransition $transition,
    ): array {
        return self::build($requirement, self::EVENT_RETURNED, $transition);
    }

    /**
     * @return PayloadArray
     */
    private static function build(
        RecruitmentRequirement $requirement,
        string $event,
        RecruitmentRequirementStatusTransition $transition,
    ): array {
        $requirement->loadMissing('notificationRecipients:id,recruitment_requirement_id,user_id');

        $additionalCcUserIds = $requirement->notificationRecipients
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $requesterUserId = $requirement->created_by !== null ? (int) $requirement->created_by : null;
        $submitterUserId = $requirement->submitted_by !== null ? (int) $requirement->submitted_by : null;
        $assignedTo = $requirement->assigned_to !== null ? (int) $requirement->assigned_to : null;

        $primaryRecipientUserId = match ($event) {
            self::EVENT_SUBMITTED, self::EVENT_REASSIGNED => $assignedTo,
            self::EVENT_APPROVED, self::EVENT_RETURNED => $requesterUserId,
            default => null,
        };

        $actorUserId = match ($event) {
            self::EVENT_APPROVED => $requirement->approved_by !== null ? (int) $requirement->approved_by : (int) $transition->performed_by,
            self::EVENT_RETURNED => $requirement->returned_by !== null ? (int) $requirement->returned_by : (int) $transition->performed_by,
            default => null,
        };

        $expectedStatus = match ($event) {
            self::EVENT_SUBMITTED, self::EVENT_REASSIGNED => RequirementStatus::PendingApproval->value,
            self::EVENT_APPROVED => RequirementStatus::Open->value,
            self::EVENT_RETURNED => RequirementStatus::Returned->value,
            default => null,
        };

        $occurredAt = $transition->created_at instanceof Carbon
            ? $transition->created_at->toIso8601String()
            : Carbon::parse((string) $transition->created_at)->toIso8601String();

        return [
            'requirement_id' => (int) $requirement->id,
            'company_id' => (int) $requirement->company_id,
            'event' => $event,
            'status_transition_id' => (int) $transition->id,
            'primary_recipient_user_id' => $primaryRecipientUserId,
            'requester_user_id' => $requesterUserId,
            'submitter_user_id' => $submitterUserId,
            'additional_cc_user_ids' => $additionalCcUserIds,
            'actor_user_id' => $actorUserId,
            'event_occurred_at' => $occurredAt,
            'return_reason' => $event === self::EVENT_RETURNED
                ? (string) ($transition->reason ?? $requirement->return_reason ?? '')
                : null,
            'expected_recruiter_id' => in_array($event, [self::EVENT_SUBMITTED, self::EVENT_REASSIGNED], true)
                ? $assignedTo
                : null,
            'expected_status' => $expectedStatus,
            'assignment_version' => $event === self::EVENT_REASSIGNED
                ? (string) $transition->id
                : null,
        ];
    }
}
