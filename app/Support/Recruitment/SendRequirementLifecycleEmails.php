<?php

namespace App\Support\Recruitment;

use App\Mail\RequirementApprovedMail;
use App\Mail\RequirementReturnedMail;
use App\Mail\RequirementSubmittedForApprovalMail;
use App\Models\Company;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendRequirementLifecycleEmails
{
    public static function submittedForApproval(RecruitmentRequirement $requirement): void
    {
        try {
            $requirement->loadMissing([
                'client:id,name',
                'project:id,title',
                'assignedRecruiter:id,name,email',
                'creator:id,name,email',
                'lines.position:id,title',
                'company:id,name',
                'notificationRecipients.user:id,name,email',
            ]);

            $recruiter = $requirement->assignedRecruiter;
            $recipients = RequirementNotificationRecipients::resolve($requirement, $recruiter);

            if ($recipients['to'] === null) {
                Log::warning('Requirement submission email skipped: recruiter has no usable email.', [
                    'requirement_id' => $requirement->id,
                    'company_id' => $requirement->company_id,
                    'assigned_to' => $requirement->assigned_to,
                ]);

                return;
            }

            $mailable = new RequirementSubmittedForApprovalMail(
                subjectLine: "Requirement {$requirement->requirement_number} awaiting approval",
                organizationName: self::organizationName($requirement),
                requirementNumber: (string) $requirement->requirement_number,
                requesterName: (string) ($requirement->creator?->name ?? 'Requester'),
                details: self::commonDetails($requirement),
                requirementUrl: self::requirementUrl($requirement),
            );

            self::queue($recipients['to'], $recipients['cc'], $mailable, $requirement, 'submitted');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public static function approved(RecruitmentRequirement $requirement): void
    {
        try {
            $requirement->loadMissing([
                'client:id,name',
                'project:id,title',
                'creator:id,name,email',
                'approver:id,name,email',
                'lines.position:id,title',
                'company:id,name',
                'notificationRecipients.user:id,name,email',
            ]);

            $requester = $requirement->creator;
            $recipients = RequirementNotificationRecipients::resolve(
                $requirement,
                $requester,
                includeRequesterInCc: false,
            );

            if ($recipients['to'] === null) {
                Log::warning('Requirement approval email skipped: requester has no usable email.', [
                    'requirement_id' => $requirement->id,
                    'company_id' => $requirement->company_id,
                    'created_by' => $requirement->created_by,
                ]);

                return;
            }

            $mailable = new RequirementApprovedMail(
                subjectLine: "Requirement {$requirement->requirement_number} approved",
                organizationName: self::organizationName($requirement),
                requirementNumber: (string) $requirement->requirement_number,
                approverName: (string) ($requirement->approver?->name ?? 'Recruiter'),
                approvedAtFormatted: $requirement->approved_at?->format('d-m-Y H:i') ?? now()->format('d-m-Y H:i'),
                details: self::commonDetails($requirement),
                requirementUrl: self::requirementUrl($requirement),
            );

            self::queue($recipients['to'], $recipients['cc'], $mailable, $requirement, 'approved');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public static function returned(RecruitmentRequirement $requirement): void
    {
        try {
            $requirement->loadMissing([
                'client:id,name',
                'project:id,title',
                'creator:id,name,email',
                'returner:id,name,email',
                'assignedRecruiter:id,name,email',
                'lines.position:id,title',
                'company:id,name',
                'notificationRecipients.user:id,name,email',
            ]);

            $requester = $requirement->creator;
            $recipients = RequirementNotificationRecipients::resolve(
                $requirement,
                $requester,
                includeRequesterInCc: false,
            );

            if ($recipients['to'] === null) {
                Log::warning('Requirement return email skipped: requester has no usable email.', [
                    'requirement_id' => $requirement->id,
                    'company_id' => $requirement->company_id,
                    'created_by' => $requirement->created_by,
                ]);

                return;
            }

            $mailable = new RequirementReturnedMail(
                subjectLine: "Requirement {$requirement->requirement_number} returned for changes",
                organizationName: self::organizationName($requirement),
                requirementNumber: (string) $requirement->requirement_number,
                recruiterName: (string) ($requirement->returner?->name ?? $requirement->assignedRecruiter?->name ?? 'Recruiter'),
                returnReason: (string) ($requirement->return_reason ?? ''),
                details: self::commonDetails($requirement),
                requirementUrl: self::requirementUrl($requirement),
            );

            self::queue($recipients['to'], $recipients['cc'], $mailable, $requirement, 'returned');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public static function pendingReassigned(RecruitmentRequirement $requirement, User $newRecruiter): void
    {
        try {
            $requirement->loadMissing([
                'client:id,name',
                'project:id,title',
                'creator:id,name,email',
                'lines.position:id,title',
                'company:id,name',
                'notificationRecipients.user:id,name,email',
            ]);

            $recipients = RequirementNotificationRecipients::resolve($requirement, $newRecruiter);

            if ($recipients['to'] === null) {
                Log::warning('Requirement reassignment email skipped: new recruiter has no usable email.', [
                    'requirement_id' => $requirement->id,
                    'company_id' => $requirement->company_id,
                    'assigned_to' => $newRecruiter->id,
                ]);

                return;
            }

            $mailable = new RequirementSubmittedForApprovalMail(
                subjectLine: "Requirement {$requirement->requirement_number} assigned for approval",
                organizationName: self::organizationName($requirement),
                requirementNumber: (string) $requirement->requirement_number,
                requesterName: (string) ($requirement->creator?->name ?? 'Requester'),
                details: self::commonDetails($requirement),
                requirementUrl: self::requirementUrl($requirement),
            );

            self::queue($recipients['to'], $recipients['cc'], $mailable, $requirement, 'reassigned');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  list<string>  $cc
     */
    private static function queue(
        string $to,
        array $cc,
        RequirementSubmittedForApprovalMail|RequirementApprovedMail|RequirementReturnedMail $mailable,
        RecruitmentRequirement $requirement,
        string $event,
    ): void {
        $pending = Mail::to($to);
        if ($cc !== []) {
            $pending->cc($cc);
        }

        $pending->queue($mailable);

        Log::info('Queued recruitment requirement lifecycle email.', [
            'event' => $event,
            'requirement_id' => $requirement->id,
            'company_id' => $requirement->company_id,
            'requirement_number' => $requirement->requirement_number,
            'to' => $to,
            'cc_count' => count($cc),
        ]);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function commonDetails(RecruitmentRequirement $requirement): array
    {
        $positions = $requirement->lines
            ->map(function ($line): string {
                $title = (string) ($line->position?->title ?? 'Position');
                $count = (int) $line->required_headcount;

                return "{$title} × {$count}";
            })
            ->filter()
            ->values()
            ->all();

        $details = [
            ['label' => 'Requirement', 'value' => (string) $requirement->requirement_number],
            ['label' => 'Client', 'value' => (string) ($requirement->client?->name ?? '—')],
        ];

        if ($requirement->project !== null) {
            $details[] = ['label' => 'Project', 'value' => (string) $requirement->project->title];
        }

        $details[] = [
            'label' => 'Positions',
            'value' => $positions === [] ? '—' : implode(', ', $positions),
        ];
        $details[] = [
            'label' => 'Required by',
            'value' => $requirement->required_by_date?->format('d-m-Y') ?? '—',
        ];
        $details[] = [
            'label' => 'Priority',
            'value' => $requirement->priority->label(),
        ];
        $details[] = [
            'label' => 'Requester',
            'value' => (string) ($requirement->creator?->name ?? '—'),
        ];

        return $details;
    }

    private static function organizationName(RecruitmentRequirement $requirement): string
    {
        if ($requirement->company instanceof Company && filled($requirement->company->name)) {
            return (string) $requirement->company->name;
        }

        return 'OMS-HRM';
    }

    private static function requirementUrl(RecruitmentRequirement $requirement): string
    {
        return route('organization.recruitment.requirements.show', $requirement);
    }
}
