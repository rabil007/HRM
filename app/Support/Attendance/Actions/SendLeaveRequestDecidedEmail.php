<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestDecidedMail;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Support\Attendance\LeaveNotificationSettings;
use App\Support\Departments\ResolveDepartmentEffectiveManager;
use App\Support\Email\CommaSeparatedEmailList;
use Illuminate\Support\Facades\Mail;

final class SendLeaveRequestDecidedEmail
{
    public function handle(LeaveRequest $leaveRequest): void
    {
        $notificationSettings = LeaveNotificationSettings::forCompany((int) $leaveRequest->company_id);

        if (! $notificationSettings->shouldNotifyOnFinalDecision()) {
            return;
        }

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
            'approvals.approverEmployee:id,name,work_email,personal_email,user_id',
            'approvals.approverEmployee.user:id,email',
            'approver:id,name',
        ]);

        $recipients = $this->resolveRecipients(
            $template,
            $leaveRequest,
            $notificationSettings->shouldCopyDecidingApprover(),
        );

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
    private function resolveRecipients(
        EmailTemplate $template,
        LeaveRequest $leaveRequest,
        bool $copyDecidingApprover,
    ): array {
        $toPreset = CommaSeparatedEmailList::parse($template->to_preset);
        $ccPreset = CommaSeparatedEmailList::parse($template->cc_preset);
        $employeeEmail = $this->resolveEmployeeEmail($leaveRequest);
        $snapshotApproverEmail = $copyDecidingApprover
            ? $this->resolveSnapshotApproverEmail($leaveRequest)
            : '';

        if ($employeeEmail === '') {
            $cc = collect([...$toPreset, ...$ccPreset, $snapshotApproverEmail])
                ->filter(fn (string $email) => $email !== '')
                ->unique(fn (string $email) => strtolower($email))
                ->values()
                ->all();

            return [
                'to' => $cc[0] ?? '',
                'cc' => array_slice($cc, 1),
            ];
        }

        $cc = collect([...$toPreset, ...$ccPreset, $snapshotApproverEmail])
            ->filter(fn (string $email) => $email !== '')
            ->filter(fn (string $email) => strcasecmp($email, $employeeEmail) !== 0)
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();

        return [
            'to' => $employeeEmail,
            'cc' => $cc,
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

    private function resolveSnapshotApproverEmail(LeaveRequest $leaveRequest): string
    {
        $approvals = $leaveRequest->relationLoaded('approvals')
            ? $leaveRequest->approvals
            : $leaveRequest->approvals()->with('approverEmployee.user:id,email')->get();

        $acted = $approvals
            ->sortByDesc('sequence')
            ->first(function (LeaveRequestApproval $approval): bool {
                $status = $approval->status instanceof LeaveRequestApprovalStatus
                    ? $approval->status
                    : LeaveRequestApprovalStatus::tryFrom((string) $approval->status);

                return $status === LeaveRequestApprovalStatus::Approved
                    || $status === LeaveRequestApprovalStatus::Rejected;
            });

        if ($acted === null) {
            return '';
        }

        $acted->loadMissing('approverEmployee.user:id,email');
        $approver = $acted->approverEmployee;

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

        return [
            'organizationName' => (string) ($leaveRequest->company?->name ?? config('app.name')),
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'employeeName' => (string) ($employee?->name ?? '—'),
            'employeeNo' => (string) ($employee?->employee_no ?? ''),
            'departmentName' => (string) ($employee?->department?->name ?? '—'),
            'managerName' => $this->resolveDecidingApproverName($leaveRequest, $employee),
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

        $replacements = [
            '{{employee_name}}' => (string) ($employee?->name ?? ''),
            '{{employee_no}}' => (string) ($employee?->employee_no ?? ''),
            '{{department_name}}' => (string) ($employee?->department?->name ?? '—'),
            '{{leave_type}}' => (string) ($leaveRequest->leaveType?->name ?? ''),
            '{{start_date}}' => $leaveRequest->start_date?->format('d M Y') ?? '',
            '{{end_date}}' => $leaveRequest->end_date?->format('d M Y') ?? '',
            '{{total_days}}' => number_format((float) $leaveRequest->total_days, 1, '.', ''),
            '{{reason}}' => filled($leaveRequest->reason) ? (string) $leaveRequest->reason : '—',
            '{{manager_name}}' => $this->resolveDecidingApproverName($leaveRequest, $employee),
            '{{company_name}}' => (string) ($leaveRequest->company?->name ?? ''),
            '{{request_url}}' => route('attendance.leave-requests.show', $leaveRequest),
            '{{rejection_reason}}' => filled($leaveRequest->rejection_reason) ? (string) $leaveRequest->rejection_reason : '—',
        ];

        return strtr($template, $replacements);
    }

    private function resolveDecidingApproverName(LeaveRequest $leaveRequest, ?Employee $employee): string
    {
        if ($leaveRequest->approver !== null) {
            return (string) $leaveRequest->approver->name;
        }

        if ($leaveRequest->relationLoaded('approvals')) {
            $acted = $leaveRequest->approvals
                ->filter(fn ($approval) => in_array(
                    $approval->status,
                    [LeaveRequestApprovalStatus::Approved, LeaveRequestApprovalStatus::Rejected],
                    true,
                ))
                ->sortByDesc('sequence')
                ->first();

            if ($acted?->approverEmployee !== null) {
                return (string) $acted->approverEmployee->name;
            }
        }

        if ($employee === null) {
            return '—';
        }

        $manager = ResolveDepartmentEffectiveManager::managerForEmployee($employee);

        return (string) ($manager?->name ?? '—');
    }
}
