<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Support\Attendance\ComposeLeaveRequestSubmittedMail;
use App\Support\Attendance\LeaveNotificationSettings;
use App\Support\Email\CommaSeparatedEmailList;
use Illuminate\Support\Facades\Mail;

/**
 * Notifies the newly activated pending approver after an intermediate approval.
 */
final class SendLeaveRequestApproverActionRequiredEmail
{
    private const TEMPLATE_SLUG = 'leave_request_approver_action_required';

    public function __construct(
        private ComposeLeaveRequestSubmittedMail $composeMail,
    ) {}

    public function handle(LeaveRequest $leaveRequest): void
    {
        if (! LeaveNotificationSettings::forCompany((int) $leaveRequest->company_id)->shouldNotifyNextApprover()) {
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

        $recipients = $this->resolveRecipients($template, $leaveRequest);

        if ($recipients['to'] === '') {
            return;
        }

        $subject = $this->composeMail->renderTemplate($template->subject, $leaveRequest);
        $introMessage = trim($this->composeMail->renderTemplate($template->body_html, $leaveRequest));
        $payload = $this->composeMail->payload($leaveRequest, $introMessage);

        $mail = Mail::to($recipients['to']);

        if ($recipients['cc'] !== []) {
            $mail->cc($recipients['cc']);
        }

        $mail->queue($this->composeMail->mailable(
            $subject,
            $payload,
            (bool) $template->include_company_footer,
        ));
    }

    /**
     * @return array{to: string, cc: list<string>}
     */
    private function resolveRecipients(EmailTemplate $template, LeaveRequest $leaveRequest): array
    {
        $toPreset = CommaSeparatedEmailList::parse($template->to_preset);
        $ccPreset = CommaSeparatedEmailList::parse($template->cc_preset);
        $pendingApproverEmail = $this->resolveFirstPendingApproverEmail($leaveRequest);

        if ($pendingApproverEmail === '') {
            return ['to' => '', 'cc' => []];
        }

        $cc = collect([...$toPreset, ...$ccPreset])
            ->filter(fn (string $email) => $email !== '')
            ->filter(fn (string $email) => strcasecmp($email, $pendingApproverEmail) !== 0)
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();

        return [
            'to' => $pendingApproverEmail,
            'cc' => $cc,
        ];
    }

    private function resolveFirstPendingApproverEmail(LeaveRequest $leaveRequest): string
    {
        $pending = $leaveRequest->relationLoaded('approvals')
            ? $leaveRequest->approvals
                ->sortBy('sequence')
                ->first(fn (LeaveRequestApproval $approval): bool => $approval->status === LeaveRequestApprovalStatus::Pending
                    && (bool) $approval->is_required)
            : LeaveRequestApproval::query()
                ->where('company_id', $leaveRequest->company_id)
                ->where('leave_request_id', $leaveRequest->id)
                ->where('status', LeaveRequestApprovalStatus::Pending)
                ->where('is_required', true)
                ->orderBy('sequence')
                ->with('approverEmployee.user:id,email')
                ->first();

        if ($pending === null) {
            return '';
        }

        $pending->loadMissing('approverEmployee.user:id,email');
        $approver = $pending->approverEmployee;

        if ($approver === null) {
            return '';
        }

        return $this->employeeEmail($approver);
    }

    private function employeeEmail(Employee $employee): string
    {
        if (filled($employee->work_email)) {
            return (string) $employee->work_email;
        }

        if (filled($employee->personal_email)) {
            return (string) $employee->personal_email;
        }

        return (string) ($employee->user?->email ?? '');
    }
}
