<?php

namespace App\Support\Attendance\Actions;

use App\Mail\LeaveRequestDecidedMail;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\Departments\ResolveDepartmentEffectiveManager;
use App\Support\Email\CommaSeparatedEmailList;
use Illuminate\Support\Facades\Mail;

final class SendLeaveRequestDecidedEmail
{
    public function handle(LeaveRequest $leaveRequest): void
    {
        $status = $leaveRequest->status; // 'approved' or 'rejected'
        $slug = $status === 'approved' ? 'leave_request_approved' : 'leave_request_rejected';

        $template = EmailTemplate::query()
            ->where('slug', $slug)
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

        $mail->queue(new LeaveRequestDecidedMail(
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
            status: $status,
            rejectionReason: $leaveRequest->rejection_reason,
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
        $employeeEmail = $this->resolveEmployeeEmail($leaveRequest);
        $managerEmail = $this->resolveManagerEmail($leaveRequest);

        $merged = collect([...$toPreset, $employeeEmail])
            ->filter(fn (string $email) => $email !== '')
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();

        if ($merged === []) {
            return ['to' => '', 'cc' => $ccPreset];
        }

        $ccMerged = collect([...$ccPreset, $managerEmail])
            ->filter(fn (string $email) => $email !== '')
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();

        return [
            'to' => $merged[0],
            'cc' => array_values(array_unique([...array_slice($merged, 1), ...$ccMerged], SORT_REGULAR)),
        ];
    }

    private function resolveEmployeeEmail(LeaveRequest $leaveRequest): string
    {
        $employee = $leaveRequest->employee;

        if ($employee === null) {
            return '';
        }

        return $this->employeeEmail($employee);
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
        $manager = $employee !== null
            ? ResolveDepartmentEffectiveManager::managerForEmployee($employee)
            : null;

        return [
            'organizationName' => (string) ($leaveRequest->company?->name ?? config('app.name')),
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'employeeName' => (string) ($employee?->name ?? '—'),
            'employeeNo' => (string) ($employee?->employee_no ?? ''),
            'departmentName' => (string) ($employee?->department?->name ?? '—'),
            'managerName' => (string) ($manager?->name ?? '—'),
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
        $manager = $employee !== null
            ? ResolveDepartmentEffectiveManager::managerForEmployee($employee)
            : null;

        $replacements = [
            '{{employee_name}}' => (string) ($employee?->name ?? ''),
            '{{employee_no}}' => (string) ($employee?->employee_no ?? ''),
            '{{department_name}}' => (string) ($employee?->department?->name ?? '—'),
            '{{leave_type}}' => (string) ($leaveRequest->leaveType?->name ?? ''),
            '{{start_date}}' => $leaveRequest->start_date?->format('d M Y') ?? '',
            '{{end_date}}' => $leaveRequest->end_date?->format('d M Y') ?? '',
            '{{total_days}}' => number_format((float) $leaveRequest->total_days, 1, '.', ''),
            '{{reason}}' => filled($leaveRequest->reason) ? (string) $leaveRequest->reason : '—',
            '{{manager_name}}' => (string) ($manager?->name ?? '—'),
            '{{company_name}}' => (string) ($leaveRequest->company?->name ?? ''),
            '{{request_url}}' => route('attendance.leave-requests.show', $leaveRequest),
            '{{rejection_reason}}' => filled($leaveRequest->rejection_reason) ? (string) $leaveRequest->rejection_reason : '—',
        ];

        return strtr($template, $replacements);
    }
}
