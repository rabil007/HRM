<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementTargetDateReminderMilestone;
use App\Models\EmailTemplate;
use App\Models\RecruitmentRequirement;

/**
 * Subject/body composition for recruitment requirement emails from Email Templates.
 */
final class ComposeRequirementLifecycleMail
{
    public const SLUG_SUBMITTED = 'requirement_submitted_for_approval';

    public const SLUG_ASSIGNED = 'requirement_assigned_for_approval';

    public const SLUG_APPROVED = 'requirement_approved';

    public const SLUG_RETURNED = 'requirement_returned';

    public const SLUG_TARGET_DATE_THREE_DAYS = 'requirement_target_date_three_days_before';

    public const SLUG_TARGET_DATE_DUE_TODAY = 'requirement_target_date_due_today';

    public const SLUG_DEADLINE_EXTENSION_REQUESTED = 'requirement_deadline_extension_requested';

    public const SLUG_DEADLINE_EXTENSION_APPROVED = 'requirement_deadline_extension_approved';

    public const SLUG_DEADLINE_EXTENSION_REJECTED = 'requirement_deadline_extension_rejected';

    public const SLUG_DEADLINE_EXTENDED_BY_REQUESTER = 'requirement_deadline_extended_by_requester';

    public function findBySlug(string $slug): ?EmailTemplate
    {
        return EmailTemplate::query()
            ->where('slug', $slug)
            ->first();
    }

    public function findEnabled(string $slug): ?EmailTemplate
    {
        return EmailTemplate::query()
            ->where('slug', $slug)
            ->where('enabled', true)
            ->first();
    }

    public function slugForLifecycleEvent(string $event): ?string
    {
        return match ($event) {
            RequirementLifecycleEmailPayload::EVENT_SUBMITTED => self::SLUG_SUBMITTED,
            RequirementLifecycleEmailPayload::EVENT_REASSIGNED => self::SLUG_ASSIGNED,
            RequirementLifecycleEmailPayload::EVENT_APPROVED => self::SLUG_APPROVED,
            RequirementLifecycleEmailPayload::EVENT_RETURNED => self::SLUG_RETURNED,
            default => null,
        };
    }

    public function slugForDeadlineExtensionEvent(string $event): ?string
    {
        return match ($event) {
            RequirementDeadlineExtensionEmailPayload::EVENT_REQUESTED => self::SLUG_DEADLINE_EXTENSION_REQUESTED,
            RequirementDeadlineExtensionEmailPayload::EVENT_APPROVED => self::SLUG_DEADLINE_EXTENSION_APPROVED,
            RequirementDeadlineExtensionEmailPayload::EVENT_REJECTED => self::SLUG_DEADLINE_EXTENSION_REJECTED,
            RequirementDeadlineExtensionEmailPayload::EVENT_DIRECT => self::SLUG_DEADLINE_EXTENDED_BY_REQUESTER,
            default => null,
        };
    }

    public function slugForTargetDateMilestone(RequirementTargetDateReminderMilestone $milestone): string
    {
        return match ($milestone) {
            RequirementTargetDateReminderMilestone::ThreeDaysBefore => self::SLUG_TARGET_DATE_THREE_DAYS,
            RequirementTargetDateReminderMilestone::TargetDay => self::SLUG_TARGET_DATE_DUE_TODAY,
        };
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    public function render(string $template, array $placeholders): string
    {
        return strtr($template, $placeholders);
    }

    /**
     * @return array<string, string>
     */
    public function lifecyclePlaceholders(
        RecruitmentRequirement $requirement,
        string $requirementUrl,
        string $submitterName = '',
        string $approverName = '',
        string $recruiterName = '',
        string $approvedAtFormatted = '',
        string $returnReason = '',
    ): array {
        $companyName = filled($requirement->company?->name)
            ? (string) $requirement->company->name
            : (string) config('app.name');

        return [
            '{{requirement_number}}' => (string) $requirement->requirement_number,
            '{{company_name}}' => $companyName,
            '{{client_name}}' => (string) ($requirement->client?->name ?? '—'),
            '{{project_name}}' => (string) ($requirement->project?->title ?? '—'),
            '{{submitter_name}}' => $submitterName,
            '{{approver_name}}' => $approverName,
            '{{recruiter_name}}' => $recruiterName,
            '{{approved_at}}' => $approvedAtFormatted,
            '{{return_reason}}' => $returnReason !== '' ? $returnReason : '—',
            '{{requirement_url}}' => $requirementUrl,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function targetDatePlaceholders(
        RecruitmentRequirement $requirement,
        string $requirementUrl,
        string $daysLabel,
        string $statusNote,
        string $targetDateFormatted,
        string $heading,
        string $milestoneLabel,
    ): array {
        $companyName = filled($requirement->company?->name)
            ? (string) $requirement->company->name
            : (string) config('app.name');

        return [
            '{{requirement_number}}' => (string) $requirement->requirement_number,
            '{{company_name}}' => $companyName,
            '{{client_name}}' => (string) ($requirement->client?->name ?? '—'),
            '{{project_name}}' => (string) ($requirement->project?->title ?? '—'),
            '{{days_label}}' => $daysLabel,
            '{{status_note}}' => $statusNote,
            '{{target_date}}' => $targetDateFormatted,
            '{{heading}}' => $heading,
            '{{milestone_label}}' => $milestoneLabel,
            '{{requirement_url}}' => $requirementUrl,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function deadlineExtensionPlaceholders(
        RecruitmentRequirement $requirement,
        string $requirementUrl,
        string $actorName = '',
        string $recruiterName = '',
        string $oldDeadline = '',
        string $newDeadline = '',
        string $reason = '',
        string $note = '',
    ): array {
        $companyName = filled($requirement->company?->name)
            ? (string) $requirement->company->name
            : (string) config('app.name');

        return [
            '{{requirement_number}}' => (string) $requirement->requirement_number,
            '{{company_name}}' => $companyName,
            '{{client_name}}' => (string) ($requirement->client?->name ?? '—'),
            '{{project_name}}' => (string) ($requirement->project?->title ?? '—'),
            '{{approver_name}}' => $actorName,
            '{{recruiter_name}}' => $recruiterName,
            '{{old_deadline}}' => $oldDeadline !== '' ? $oldDeadline : '—',
            '{{new_deadline}}' => $newDeadline !== '' ? $newDeadline : '—',
            '{{reason}}' => $reason !== '' ? $reason : '—',
            '{{note}}' => $note !== '' ? $note : '',
            '{{requirement_url}}' => $requirementUrl,
        ];
    }
}
