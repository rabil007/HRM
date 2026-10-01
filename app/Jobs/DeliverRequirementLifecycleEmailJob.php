<?php

namespace App\Jobs;

use App\Mail\RequirementApprovedMail;
use App\Mail\RequirementReturnedMail;
use App\Mail\RequirementSubmittedForApprovalMail;
use App\Models\Company;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use App\Support\Recruitment\RequirementNotificationRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverRequirementLifecycleEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $requirementId,
        public int $companyId,
        public string $event,
    ) {}

    public function handle(): void
    {
        try {
            $requirement = RecruitmentRequirement::query()
                ->whereKey($this->requirementId)
                ->where('company_id', $this->companyId)
                ->with([
                    'client:id,name',
                    'project:id,title',
                    'assignedRecruiter:id,name,email,status,deleted_at',
                    'creator:id,name,email,status,deleted_at',
                    'submitter:id,name,email,status,deleted_at',
                    'approver:id,name,email,status,deleted_at',
                    'returner:id,name,email,status,deleted_at',
                    'lines.position:id,title',
                    'company:id,name',
                    'notificationRecipients.user:id,name,email,status,deleted_at',
                ])
                ->first();

            if ($requirement === null) {
                Log::warning('Requirement lifecycle email skipped: requirement not found.', [
                    'requirement_id' => $this->requirementId,
                    'company_id' => $this->companyId,
                    'event' => $this->event,
                ]);

                return;
            }

            match ($this->event) {
                'submitted', 'reassigned' => $this->sendSubmitted($requirement),
                'approved' => $this->sendApproved($requirement),
                'returned' => $this->sendReturned($requirement),
                default => Log::warning('Requirement lifecycle email skipped: unknown event.', [
                    'requirement_id' => $this->requirementId,
                    'company_id' => $this->companyId,
                    'event' => $this->event,
                ]),
            };
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function sendSubmitted(RecruitmentRequirement $requirement): void
    {
        $primary = $requirement->assignedRecruiter;
        $resolved = RequirementNotificationRecipients::resolveUserIds(
            $requirement,
            $primary,
            $this->ccCandidatesForSubmission($requirement),
            primaryMustBeEligibleApprover: true,
        );

        if ($resolved['to_user_id'] === null) {
            Log::warning('Requirement submission email skipped: primary recruiter ineligible.', [
                'requirement_id' => $requirement->id,
                'company_id' => $requirement->company_id,
                'assigned_to' => $requirement->assigned_to,
                'event' => $this->event,
            ]);

            return;
        }

        $toUser = User::query()->find($resolved['to_user_id']);
        $toEmail = RequirementNotificationRecipients::usableEmail($toUser);
        if ($toEmail === null) {
            return;
        }

        $ccEmails = $this->emailsForUserIds($resolved['cc_user_ids'], $toEmail);
        $submitterName = (string) ($requirement->submitter?->name
            ?? $requirement->creator?->name
            ?? 'Requester');

        $mailable = new RequirementSubmittedForApprovalMail(
            subjectLine: $this->event === 'reassigned'
                ? "Requirement {$requirement->requirement_number} assigned for approval"
                : "Requirement {$requirement->requirement_number} awaiting approval",
            organizationName: $this->organizationName($requirement),
            requirementNumber: (string) $requirement->requirement_number,
            submitterName: $submitterName,
            details: $this->commonDetails($requirement, $submitterName),
            requirementUrl: $this->requirementUrl($requirement),
        );

        $this->sendMail($toEmail, $ccEmails, $mailable, $requirement);
    }

    private function sendApproved(RecruitmentRequirement $requirement): void
    {
        $primary = $requirement->creator;
        $resolved = RequirementNotificationRecipients::resolveUserIds(
            $requirement,
            $primary,
            $this->ccCandidatesForDecision($requirement),
            primaryMustBeEligibleApprover: false,
        );

        if ($resolved['to_user_id'] === null) {
            Log::warning('Requirement approval email skipped: requester ineligible.', [
                'requirement_id' => $requirement->id,
                'company_id' => $requirement->company_id,
                'created_by' => $requirement->created_by,
            ]);

            return;
        }

        $toUser = User::query()->find($resolved['to_user_id']);
        $toEmail = RequirementNotificationRecipients::usableEmail($toUser);
        if ($toEmail === null) {
            return;
        }

        $ccEmails = $this->emailsForUserIds($resolved['cc_user_ids'], $toEmail);

        $mailable = new RequirementApprovedMail(
            subjectLine: "Requirement {$requirement->requirement_number} approved",
            organizationName: $this->organizationName($requirement),
            requirementNumber: (string) $requirement->requirement_number,
            approverName: (string) ($requirement->approver?->name ?? 'Recruiter'),
            approvedAtFormatted: $requirement->approved_at?->format('d-m-Y H:i') ?? now()->format('d-m-Y H:i'),
            details: $this->commonDetails($requirement),
            requirementUrl: $this->requirementUrl($requirement),
        );

        $this->sendMail($toEmail, $ccEmails, $mailable, $requirement);
    }

    private function sendReturned(RecruitmentRequirement $requirement): void
    {
        $primary = $requirement->creator;
        $resolved = RequirementNotificationRecipients::resolveUserIds(
            $requirement,
            $primary,
            $this->ccCandidatesForDecision($requirement),
            primaryMustBeEligibleApprover: false,
        );

        if ($resolved['to_user_id'] === null) {
            Log::warning('Requirement return email skipped: requester ineligible.', [
                'requirement_id' => $requirement->id,
                'company_id' => $requirement->company_id,
                'created_by' => $requirement->created_by,
            ]);

            return;
        }

        $toUser = User::query()->find($resolved['to_user_id']);
        $toEmail = RequirementNotificationRecipients::usableEmail($toUser);
        if ($toEmail === null) {
            return;
        }

        $ccEmails = $this->emailsForUserIds($resolved['cc_user_ids'], $toEmail);

        $mailable = new RequirementReturnedMail(
            subjectLine: "Requirement {$requirement->requirement_number} returned for changes",
            organizationName: $this->organizationName($requirement),
            requirementNumber: (string) $requirement->requirement_number,
            recruiterName: (string) ($requirement->returner?->name ?? $requirement->assignedRecruiter?->name ?? 'Recruiter'),
            returnReason: (string) ($requirement->return_reason ?? ''),
            details: $this->commonDetails($requirement),
            requirementUrl: $this->requirementUrl($requirement),
        );

        $this->sendMail($toEmail, $ccEmails, $mailable, $requirement);
    }

    /**
     * @return list<User>
     */
    private function ccCandidatesForSubmission(RecruitmentRequirement $requirement): array
    {
        $users = [];

        if ($requirement->creator !== null) {
            $users[] = $requirement->creator;
        }

        if (
            $requirement->submitter !== null
            && (int) $requirement->submitter->id !== (int) ($requirement->creator?->id ?? 0)
        ) {
            $users[] = $requirement->submitter;
        } elseif ($requirement->submitter !== null) {
            // Same person — already included via creator; keep single entry.
        }

        foreach ($requirement->notificationRecipients as $recipient) {
            if ($recipient->user !== null) {
                $users[] = $recipient->user;
            }
        }

        return $users;
    }

    /**
     * @return list<User>
     */
    private function ccCandidatesForDecision(RecruitmentRequirement $requirement): array
    {
        $users = [];

        if (
            $requirement->submitter !== null
            && (int) $requirement->submitter->id !== (int) ($requirement->creator?->id ?? 0)
        ) {
            $users[] = $requirement->submitter;
        }

        foreach ($requirement->notificationRecipients as $recipient) {
            if ($recipient->user !== null) {
                $users[] = $recipient->user;
            }
        }

        return $users;
    }

    /**
     * @param  list<int>  $userIds
     * @return list<string>
     */
    private function emailsForUserIds(array $userIds, string $excludeEmail): array
    {
        if ($userIds === []) {
            return [];
        }

        $emails = [];
        $excludeKey = strtolower($excludeEmail);

        $users = User::query()->whereIn('id', $userIds)->get(['id', 'email', 'status', 'deleted_at']);
        foreach ($users as $user) {
            $email = RequirementNotificationRecipients::usableEmail($user);
            if ($email === null) {
                continue;
            }
            if (strtolower($email) === $excludeKey) {
                continue;
            }
            $emails[] = $email;
        }

        return RequirementNotificationRecipients::dedupeEmails($emails);
    }

    /**
     * @param  list<string>  $cc
     */
    private function sendMail(
        string $to,
        array $cc,
        RequirementSubmittedForApprovalMail|RequirementApprovedMail|RequirementReturnedMail $mailable,
        RecruitmentRequirement $requirement,
    ): void {
        $pending = Mail::to($to);
        if ($cc !== []) {
            $pending->cc($cc);
        }

        // Send synchronously inside the lifecycle job so addresses are not serialized ahead of time.
        $pending->send($mailable);

        Log::info('Sent recruitment requirement lifecycle email.', [
            'event' => $this->event,
            'requirement_id' => $requirement->id,
            'company_id' => $requirement->company_id,
            'requirement_number' => $requirement->requirement_number,
            'cc_count' => count($cc),
        ]);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function commonDetails(RecruitmentRequirement $requirement, ?string $submitterName = null): array
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

        if ($submitterName !== null) {
            $details[] = [
                'label' => 'Submitted by',
                'value' => $submitterName,
            ];
        }

        return $details;
    }

    private function organizationName(RecruitmentRequirement $requirement): string
    {
        if ($requirement->company instanceof Company && filled($requirement->company->name)) {
            return (string) $requirement->company->name;
        }

        return 'OMS-HRM';
    }

    private function requirementUrl(RecruitmentRequirement $requirement): string
    {
        return route('organization.recruitment.requirements.show', $requirement);
    }
}
