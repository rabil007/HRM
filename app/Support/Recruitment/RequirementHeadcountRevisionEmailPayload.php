<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementHeadcountRevisionInitiator;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Models\RecruitmentRequirementHeadcountRevisionLine;
use Illuminate\Support\Carbon;

final class RequirementHeadcountRevisionEmailPayload
{
    public const EVENT_REQUESTED = 'requested';

    public const EVENT_APPROVED = 'approved';

    public const EVENT_REJECTED = 'rejected';

    /**
     * @return array<string, mixed>
     */
    public static function forRequested(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementHeadcountRevision $revision,
    ): array {
        $recipient = $revision->initiator === RequirementHeadcountRevisionInitiator::Recruiter
            ? $requirement->created_by
            : $requirement->assigned_to;

        return self::build($requirement, $revision, self::EVENT_REQUESTED, $recipient !== null ? (int) $recipient : null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forApproved(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementHeadcountRevision $revision,
    ): array {
        return self::build($requirement, $revision, self::EVENT_APPROVED, (int) $revision->requested_by);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forRejected(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementHeadcountRevision $revision,
    ): array {
        return self::build($requirement, $revision, self::EVENT_REJECTED, (int) $revision->requested_by);
    }

    /**
     * @return array<string, mixed>
     */
    private static function build(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementHeadcountRevision $revision,
        string $event,
        ?int $primaryRecipientUserId,
    ): array {
        $requirement->loadMissing('notificationRecipients:id,recruitment_requirement_id,user_id');
        $revision->loadMissing('lines');

        $additionalCcUserIds = $requirement->notificationRecipients
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $occurredAt = $revision->updated_at instanceof Carbon
            ? $revision->updated_at->toIso8601String()
            : Carbon::parse((string) ($revision->updated_at ?? now()))->toIso8601String();

        return [
            'requirement_id' => (int) $requirement->id,
            'company_id' => (int) $requirement->company_id,
            'revision_id' => (int) $revision->id,
            'event' => $event,
            'primary_recipient_user_id' => $primaryRecipientUserId,
            'additional_cc_user_ids' => $additionalCcUserIds,
            'actor_user_id' => (int) ($revision->decided_by ?? $revision->requested_by),
            'requested_by_user_id' => (int) $revision->requested_by,
            'initiator' => $revision->initiator->value,
            'reason' => filled($revision->reason) ? (string) $revision->reason : null,
            'note' => filled($revision->decision_note) ? (string) $revision->decision_note : null,
            'headcount_changes' => self::changesText($revision),
            'event_occurred_at' => $occurredAt,
            'expected_status' => $revision->status->value,
        ];
    }

    private static function changesText(RecruitmentRequirementHeadcountRevision $revision): string
    {
        return $revision->lines
            ->map(fn (RecruitmentRequirementHeadcountRevisionLine $line): string => sprintf(
                '%s: %d → %d',
                $line->position_title,
                (int) $line->old_headcount,
                (int) $line->requested_headcount,
            ))
            ->implode("\n");
    }
}
