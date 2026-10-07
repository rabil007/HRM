<?php

namespace App\Support\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use Illuminate\Support\Carbon;

/**
 * Immutable snapshot for deadline-extension email jobs.
 *
 * @phpstan-type PayloadArray array{
 *     requirement_id: int,
 *     company_id: int,
 *     extension_id: int,
 *     event: string,
 *     primary_recipient_user_id: int|null,
 *     additional_cc_user_ids: list<int>,
 *     actor_user_id: int,
 *     requested_by_user_id: int,
 *     old_deadline: string,
 *     requested_deadline: string,
 *     reason: string|null,
 *     note: string|null,
 *     event_occurred_at: string,
 *     expected_status: string
 * }
 */
final class RequirementDeadlineExtensionEmailPayload
{
    public const EVENT_REQUESTED = 'requested';

    public const EVENT_APPROVED = 'approved';

    public const EVENT_REJECTED = 'rejected';

    public const EVENT_DIRECT = 'direct';

    /**
     * @return PayloadArray
     */
    public static function forRequested(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $extension,
    ): array {
        return self::build(
            $requirement,
            $extension,
            self::EVENT_REQUESTED,
            $requirement->created_by !== null ? (int) $requirement->created_by : null,
        );
    }

    /**
     * @return PayloadArray
     */
    public static function forApproved(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $extension,
    ): array {
        return self::build(
            $requirement,
            $extension,
            self::EVENT_APPROVED,
            (int) $extension->requested_by,
        );
    }

    /**
     * @return PayloadArray
     */
    public static function forRejected(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $extension,
    ): array {
        return self::build(
            $requirement,
            $extension,
            self::EVENT_REJECTED,
            (int) $extension->requested_by,
        );
    }

    /**
     * @return PayloadArray
     */
    public static function forDirect(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $extension,
    ): array {
        $assignedTo = $requirement->assigned_to !== null ? (int) $requirement->assigned_to : null;

        return self::build(
            $requirement,
            $extension,
            self::EVENT_DIRECT,
            $assignedTo,
        );
    }

    /**
     * @return PayloadArray
     */
    private static function build(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $extension,
        string $event,
        ?int $primaryRecipientUserId,
    ): array {
        $requirement->loadMissing('notificationRecipients:id,recruitment_requirement_id,user_id');

        $additionalCcUserIds = $requirement->notificationRecipients
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $occurredAt = $extension->updated_at instanceof Carbon
            ? $extension->updated_at->toIso8601String()
            : Carbon::parse((string) ($extension->updated_at ?? now()))->toIso8601String();

        return [
            'requirement_id' => (int) $requirement->id,
            'company_id' => (int) $requirement->company_id,
            'extension_id' => (int) $extension->id,
            'event' => $event,
            'primary_recipient_user_id' => $primaryRecipientUserId,
            'additional_cc_user_ids' => $additionalCcUserIds,
            'actor_user_id' => (int) ($extension->decided_by ?? $extension->requested_by),
            'requested_by_user_id' => (int) $extension->requested_by,
            'old_deadline' => $extension->old_deadline?->format('Y-m-d') ?? '',
            'requested_deadline' => $extension->requested_deadline?->format('Y-m-d') ?? '',
            'reason' => filled($extension->reason) ? (string) $extension->reason : null,
            'note' => filled($extension->decision_note) ? (string) $extension->decision_note : null,
            'event_occurred_at' => $occurredAt,
            'expected_status' => $extension->status->value,
        ];
    }
}
