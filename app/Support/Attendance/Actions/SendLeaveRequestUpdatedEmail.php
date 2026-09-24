<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveApprovalMode;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Support\Attendance\ComposeLeaveRequestSubmittedMail;
use App\Support\Attendance\LeaveNotificationSettings;
use App\Support\Email\CommaSeparatedEmailList;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Notifies currently pending required approvers after a pre-action leave-request edit.
 */
final class SendLeaveRequestUpdatedEmail
{
    private const TEMPLATE_SLUG = 'leave_request_updated';

    private const ANY_REQUIRED_ACTIONABLE_NOTE = 'Only one required approver needs to act. The first approval or rejection completes this request.';

    public function __construct(
        private ComposeLeaveRequestSubmittedMail $composeMail,
    ) {}

    public function handle(LeaveRequest $leaveRequest): void
    {
        if (! LeaveNotificationSettings::forCompany((int) $leaveRequest->company_id)->shouldNotifyOnUpdate()) {
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

        if ($pendingApproverEmails === []) {
            return;
        }

        $subject = $this->composeMail->renderTemplate($template->subject, $leaveRequest);
        $introMessage = trim($this->composeMail->renderTemplate($template->body_html, $leaveRequest));
        $payload = $this->composeMail->payload(
            $leaveRequest,
            $this->withAnyRequiredActionableNote($leaveRequest, $introMessage),
        );

        $toPreset = CommaSeparatedEmailList::parse($template->to_preset);
        $ccPreset = CommaSeparatedEmailList::parse($template->cc_preset);
        $presetsApplied = false;

        foreach ($pendingApproverEmails as $approverEmail) {
            $cc = [];

            if (! $presetsApplied) {
                $cc = $this->presetRecipientsExcludingApprovers(
                    toPreset: $toPreset,
                    ccPreset: $ccPreset,
                    pendingApproverEmails: $pendingApproverEmails,
                );
                $presetsApplied = true;
            }

            $mail = Mail::to($approverEmail);

            if ($cc !== []) {
                $mail->cc($cc);
            }

            $mail->queue($this->composeMail->mailable(
                $subject,
                $payload,
                (bool) $template->include_company_footer,
            ));
        }
    }

    /**
     * @param  list<string>  $toPreset
     * @param  list<string>  $ccPreset
     * @param  list<string>  $pendingApproverEmails
     * @return list<string>
     */
    private function presetRecipientsExcludingApprovers(
        array $toPreset,
        array $ccPreset,
        array $pendingApproverEmails,
    ): array {
        $excluded = collect($pendingApproverEmails)
            ->filter(fn (string $email) => $email !== '')
            ->map(fn (string $email) => strtolower($email))
            ->unique()
            ->all();

        return collect([...$toPreset, ...$ccPreset])
            ->filter(fn (string $email) => $email !== '')
            ->reject(fn (string $email) => in_array(strtolower($email), $excluded, true))
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function resolvePendingRequiredApproverEmails(LeaveRequest $leaveRequest): array
    {
        /** @var Collection<int, LeaveRequestApproval> $pending */
        $pending = $leaveRequest->relationLoaded('approvals')
            ? $leaveRequest->approvals
                ->sortBy('sequence')
                ->filter(fn (LeaveRequestApproval $approval): bool => $approval->status === LeaveRequestApprovalStatus::Pending
                    && (bool) $approval->is_required)
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

    private function withAnyRequiredActionableNote(LeaveRequest $leaveRequest, string $introMessage): string
    {
        if ($leaveRequest->approvalMode() !== LeaveApprovalMode::AnyRequired) {
            return $introMessage;
        }

        $note = self::ANY_REQUIRED_ACTIONABLE_NOTE;

        if ($introMessage === '') {
            return $note;
        }

        if (str_contains($introMessage, $note)) {
            return $introMessage;
        }

        return rtrim($introMessage)."\n\n".$note;
    }
}
