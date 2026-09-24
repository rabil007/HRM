<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveApprovalMode;
use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Support\Attendance\LeaveNotificationSettings;
use App\Support\Departments\ResolveDepartmentEffectiveManager;
use App\Support\Email\CommaSeparatedEmailList;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

final class SendLeaveRequestSubmittedEmail
{
    private const TEMPLATE_SLUG = 'leave_request_submitted';

    private const ANY_REQUIRED_ACTIONABLE_NOTE = 'Only one required approver needs to act. The first approval or rejection completes this request.';

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

        $subject = $this->renderTemplate($template->subject, $leaveRequest);
        $introMessage = trim($this->renderTemplate($template->body_html, $leaveRequest));
        $pendingApproverEmails = $this->resolvePendingRequiredApproverEmails($leaveRequest);

        if ($pendingApproverEmails !== []) {
            $payload = $this->buildMailPayload(
                $leaveRequest,
                $this->withAnyRequiredActionableNote($leaveRequest, $introMessage),
            );
            $this->queueActionRequiredMails(
                template: $template,
                subject: $subject,
                payload: $payload,
                pendingApproverEmails: $pendingApproverEmails,
            );

            return;
        }

        // Legacy fallback when no approval snapshot exists yet.
        $payload = $this->buildMailPayload($leaveRequest, $introMessage);
        $recipients = $this->resolveLegacyRecipients($template, $leaveRequest);

        if ($recipients['to'] === '') {
            return;
        }

        $mail = Mail::to($recipients['to']);

        if ($recipients['cc'] !== []) {
            $mail->cc($recipients['cc']);
        }

        $mail->queue(new LeaveRequestSubmittedMail(
            subjectLine: $subject,
            organizationName: $payload['organizationName'],
            introMessage: $payload['introMessage'],
            employeeName: $payload['employeeName'],
            employeeNo: $payload['employeeNo'],
            departmentName: $payload['departmentName'],
            managerName: $payload['managerName'],
            leaveType: $payload['leaveType'],
            leaveTypeColor: $payload['leaveTypeColor'],
            startDate: $payload['startDate'],
            endDate: $payload['endDate'],
            totalDays: $payload['totalDays'],
            reason: $payload['reason'],
            requestUrl: $payload['requestUrl'],
            includeCompanyFooter: $template->include_company_footer,
        ));
    }

    /**
     * @param  list<string>  $pendingApproverEmails
     * @param  array{
     *     organizationName: string,
     *     introMessage: string|null,
     *     employeeName: string,
     *     employeeNo: string,
     *     departmentName: string,
     *     managerName: string,
     *     leaveType: string,
     *     leaveTypeColor: string|null,
     *     startDate: string,
     *     endDate: string,
     *     totalDays: string,
     *     reason: string,
     *     requestUrl: string,
     * }  $payload
     */
    private function queueActionRequiredMails(
        EmailTemplate $template,
        string $subject,
        array $payload,
        array $pendingApproverEmails,
    ): void {
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

            $mail->queue(new LeaveRequestSubmittedMail(
                subjectLine: $subject,
                organizationName: $payload['organizationName'],
                introMessage: $payload['introMessage'],
                employeeName: $payload['employeeName'],
                employeeNo: $payload['employeeNo'],
                departmentName: $payload['departmentName'],
                managerName: $payload['managerName'],
                leaveType: $payload['leaveType'],
                leaveTypeColor: $payload['leaveTypeColor'],
                startDate: $payload['startDate'],
                endDate: $payload['endDate'],
                totalDays: $payload['totalDays'],
                reason: $payload['reason'],
                requestUrl: $payload['requestUrl'],
                includeCompanyFooter: $template->include_company_footer,
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
     * @return array{to: string, cc: list<string>}
     */
    private function resolveLegacyRecipients(EmailTemplate $template, LeaveRequest $leaveRequest): array
    {
        $toPreset = CommaSeparatedEmailList::parse($template->to_preset);
        $ccPreset = CommaSeparatedEmailList::parse($template->cc_preset);
        $managerEmail = $this->resolveManagerEmail($leaveRequest);

        $merged = collect([...$toPreset, $managerEmail])
            ->filter(fn (string $email) => $email !== '')
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();

        if ($merged === []) {
            return ['to' => '', 'cc' => $ccPreset];
        }

        $primary = $merged[0];
        $cc = collect([...array_slice($merged, 1), ...$ccPreset])
            ->filter(fn (string $email) => $email !== '')
            ->filter(fn (string $email) => strcasecmp($email, $primary) !== 0)
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();

        return [
            'to' => $primary,
            'cc' => $cc,
        ];
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

    private function resolveManagerEmail(LeaveRequest $leaveRequest): string
    {
        $employee = $leaveRequest->employee;

        if ($employee === null) {
            return '';
        }

        $managerSummary = ResolveDepartmentEffectiveManager::managerForEmployee($employee);

        if ($managerSummary === null) {
            return '';
        }

        $manager = Employee::query()
            ->where('company_id', $employee->company_id)
            ->whereKey($managerSummary->id)
            ->with('user:id,email')
            ->first(['id', 'work_email', 'personal_email', 'user_id']);

        if ($manager === null) {
            return '';
        }

        return $this->employeeEmail($manager);
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

    /**
     * @return array{
     *     organizationName: string,
     *     introMessage: string|null,
     *     employeeName: string,
     *     employeeNo: string,
     *     departmentName: string,
     *     managerName: string,
     *     leaveType: string,
     *     leaveTypeColor: string|null,
     *     startDate: string,
     *     endDate: string,
     *     totalDays: string,
     *     reason: string,
     *     requestUrl: string,
     * }
     */
    private function buildMailPayload(LeaveRequest $leaveRequest, string $introMessage): array
    {
        $employee = $leaveRequest->employee;
        $managerName = $this->resolveDisplayApproverName($leaveRequest, $employee);

        return [
            'organizationName' => (string) ($leaveRequest->company?->name ?? config('app.name')),
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'employeeName' => (string) ($employee?->name ?? '—'),
            'employeeNo' => (string) ($employee?->employee_no ?? ''),
            'departmentName' => (string) ($employee?->department?->name ?? '—'),
            'managerName' => $managerName,
            'leaveType' => (string) ($leaveRequest->leaveType?->name ?? '—'),
            'leaveTypeColor' => $leaveRequest->leaveType?->color,
            'startDate' => $leaveRequest->start_date?->format('d M Y') ?? '—',
            'endDate' => $leaveRequest->end_date?->format('d M Y') ?? '—',
            'totalDays' => number_format((float) $leaveRequest->total_days, 1, '.', ''),
            'reason' => filled($leaveRequest->reason) ? (string) $leaveRequest->reason : '—',
            'requestUrl' => route('attendance.leave-requests.show', $leaveRequest),
        ];
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

    private function renderTemplate(string $template, LeaveRequest $leaveRequest): string
    {
        $employee = $leaveRequest->employee;
        $managerName = $this->resolveDisplayApproverName($leaveRequest, $employee);

        $replacements = [
            '{{employee_name}}' => (string) ($employee?->name ?? ''),
            '{{employee_no}}' => (string) ($employee?->employee_no ?? ''),
            '{{department_name}}' => (string) ($employee?->department?->name ?? '—'),
            '{{leave_type}}' => (string) ($leaveRequest->leaveType?->name ?? ''),
            '{{start_date}}' => $leaveRequest->start_date?->format('d M Y') ?? '',
            '{{end_date}}' => $leaveRequest->end_date?->format('d M Y') ?? '',
            '{{total_days}}' => number_format((float) $leaveRequest->total_days, 1, '.', ''),
            '{{reason}}' => filled($leaveRequest->reason) ? (string) $leaveRequest->reason : '—',
            '{{manager_name}}' => $managerName,
            '{{company_name}}' => (string) ($leaveRequest->company?->name ?? ''),
            '{{request_url}}' => route('attendance.leave-requests.show', $leaveRequest),
        ];

        return strtr($template, $replacements);
    }

    private function resolveDisplayApproverName(LeaveRequest $leaveRequest, ?Employee $employee): string
    {
        $pending = $leaveRequest->relationLoaded('approvals')
            ? $leaveRequest->approvals
                ->sortBy('sequence')
                ->first(fn (LeaveRequestApproval $approval): bool => $approval->status === LeaveRequestApprovalStatus::Pending
                    && (bool) $approval->is_required)
            : null;

        if ($pending?->approverEmployee !== null) {
            return (string) $pending->approverEmployee->name;
        }

        if ($employee === null) {
            return '—';
        }

        $manager = ResolveDepartmentEffectiveManager::managerForEmployee($employee);

        return (string) ($manager?->name ?? '—');
    }
}
