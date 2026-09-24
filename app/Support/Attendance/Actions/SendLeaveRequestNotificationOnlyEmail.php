<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Support\Attendance\ComposeLeaveRequestSubmittedMail;
use App\Support\Attendance\LeaveNotificationSettings;
use Illuminate\Support\Facades\Mail;

/**
 * Sends FYI submission mail to notify-only (Required = OFF) policy recipients.
 *
 * Does not grant approval rights and never CCs other FYI recipients together.
 */
final class SendLeaveRequestNotificationOnlyEmail
{
    private const TEMPLATE_SLUG = 'leave_request_notification_only';

    public function __construct(
        private ComposeLeaveRequestSubmittedMail $composeMail,
    ) {}

    public function handle(LeaveRequest $leaveRequest): void
    {
        if (! LeaveNotificationSettings::forCompany((int) $leaveRequest->company_id)->shouldNotifyOnSubmission()) {
            return;
        }

        $template = EmailTemplate::query()
            ->where('slug', self::TEMPLATE_SLUG)
            ->where('enabled', true)
            ->first();

        if ($template === null) {
            return;
        }

        $leaveRequest->loadMissing([
            'employee.department',
            'employee.user:id,email',
            'leaveType',
            'company',
            'approvals.approverEmployee.user:id,email',
        ]);

        $pendingApproverEmails = $this->resolvePendingRequiredApproverEmails($leaveRequest);
        $subject = $this->composeMail->renderTemplate($template->subject, $leaveRequest);
        $introMessage = trim($this->composeMail->renderTemplate($template->body_html, $leaveRequest));
        $payload = $this->composeMail->payload($leaveRequest, $introMessage);

        $sent = [];

        foreach ($this->resolveNotifyOnlyRecipients($leaveRequest) as $email) {
            if ($email === '') {
                continue;
            }

            if ($this->emailIsPendingRequiredApprover($email, $pendingApproverEmails)) {
                continue;
            }

            $normalized = strtolower($email);

            if (isset($sent[$normalized])) {
                continue;
            }

            $sent[$normalized] = true;

            Mail::to($email)->queue($this->composeMail->mailable(
                $subject,
                $payload,
                (bool) $template->include_company_footer,
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function resolveNotifyOnlyRecipients(LeaveRequest $leaveRequest): array
    {
        $approvals = $leaveRequest->relationLoaded('approvals')
            ? $leaveRequest->approvals
            : LeaveRequestApproval::query()
                ->where('company_id', $leaveRequest->company_id)
                ->where('leave_request_id', $leaveRequest->id)
                ->with('approverEmployee.user:id,email')
                ->orderBy('sequence')
                ->get();

        $emails = [];

        foreach ($approvals as $approval) {
            if ($approval->is_required) {
                continue;
            }

            if ($approval->status !== LeaveRequestApprovalStatus::Skipped) {
                continue;
            }

            if ((int) $approval->company_id !== (int) $leaveRequest->company_id) {
                continue;
            }

            $approval->loadMissing('approverEmployee.user:id,email');
            $email = $this->employeeEmail($approval->approverEmployee);

            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * @return list<string>
     */
    private function resolvePendingRequiredApproverEmails(LeaveRequest $leaveRequest): array
    {
        $pending = $leaveRequest->relationLoaded('approvals')
            ? $leaveRequest->approvals
                ->sortBy('sequence')
                ->filter(fn (LeaveRequestApproval $approval): bool => $approval->status === LeaveRequestApprovalStatus::Pending
                    && $approval->is_required)
                ->values()
            : LeaveRequestApproval::query()
                ->where('company_id', $leaveRequest->company_id)
                ->where('leave_request_id', $leaveRequest->id)
                ->where('status', LeaveRequestApprovalStatus::Pending)
                ->where('is_required', true)
                ->orderBy('sequence')
                ->with('approverEmployee.user:id,email')
                ->get();

        $emails = [];
        $seen = [];

        foreach ($pending as $approval) {
            $approval->loadMissing('approverEmployee.user:id,email');
            $email = $this->employeeEmail($approval->approverEmployee);

            if ($email === '') {
                continue;
            }

            $normalized = strtolower($email);

            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $emails[] = $email;
        }

        return $emails;
    }

    /**
     * @param  list<string>  $pendingApproverEmails
     */
    private function emailIsPendingRequiredApprover(string $email, array $pendingApproverEmails): bool
    {
        foreach ($pendingApproverEmails as $pendingEmail) {
            if (strcasecmp($email, $pendingEmail) === 0) {
                return true;
            }
        }

        return false;
    }

    private function employeeEmail(?Employee $employee): string
    {
        if ($employee === null) {
            return '';
        }

        if (filled($employee->work_email)) {
            return (string) $employee->work_email;
        }

        if (filled($employee->personal_email)) {
            return (string) $employee->personal_email;
        }

        return (string) ($employee->user?->email ?? '');
    }
}
