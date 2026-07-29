<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Support\Departments\ResolveDepartmentEffectiveManager;
use App\Support\Email\CommaSeparatedEmailList;
use Illuminate\Support\Facades\Mail;

/**
 * Notifies the newly activated pending approver after an intermediate approval.
 */
final class SendLeaveRequestApproverActionRequiredEmail
{
    private const TEMPLATE_SLUG = 'leave_request_approver_action_required';

    private const FALLBACK_TEMPLATE_SLUG = 'leave_request_submitted';

    public function handle(LeaveRequest $leaveRequest): void
    {
        $template = EmailTemplate::query()
            ->where('slug', self::TEMPLATE_SLUG)
            ->where('enabled', true)
            ->first();

        if ($template === null) {
            $template = EmailTemplate::query()
                ->where('slug', self::FALLBACK_TEMPLATE_SLUG)
                ->where('enabled', true)
                ->first();
        }

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

        $subject = $this->renderTemplate($template->subject, $leaveRequest);
        $introMessage = trim($this->renderTemplate($template->body_html, $leaveRequest));
        $payload = $this->buildMailPayload($leaveRequest, $introMessage);

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
                ->first(fn (LeaveRequestApproval $approval): bool => $approval->status === LeaveRequestApprovalStatus::Pending)
            : LeaveRequestApproval::query()
                ->where('company_id', $leaveRequest->company_id)
                ->where('leave_request_id', $leaveRequest->id)
                ->where('status', LeaveRequestApprovalStatus::Pending)
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
                ->first(fn (LeaveRequestApproval $approval): bool => $approval->status === LeaveRequestApprovalStatus::Pending)
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
