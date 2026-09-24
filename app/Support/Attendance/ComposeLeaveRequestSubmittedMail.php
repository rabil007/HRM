<?php

namespace App\Support\Attendance;

use App\Mail\LeaveRequestSubmittedMail;
use App\Models\LeaveRequest;
use App\Support\Attendance\Data\LeaveRequestEmailApprovalContext;

/**
 * Shared leave-request submission/update/FYI/action-required mail composition.
 */
final class ComposeLeaveRequestSubmittedMail
{
    public function __construct(
        private PresentLeaveRequestEmailApprovalContext $presentApproval,
    ) {}

    /**
     * @return array{
     *     organizationName: string,
     *     introMessage: string|null,
     *     employeeName: string,
     *     employeeNo: string,
     *     departmentName: string,
     *     approvalLabel: string|null,
     *     approvalNames: list<string>,
     *     approvalHelpText: string|null,
     *     leaveType: string,
     *     leaveTypeColor: string|null,
     *     startDate: string,
     *     endDate: string,
     *     totalDays: string,
     *     reason: string,
     *     requestUrl: string,
     * }
     */
    public function payload(LeaveRequest $leaveRequest, string $introMessage): array
    {
        $employee = $leaveRequest->employee;
        $approval = $this->presentApproval->handle($leaveRequest);

        return [
            'organizationName' => (string) ($leaveRequest->company?->name ?? config('app.name')),
            'introMessage' => $introMessage !== '' ? $introMessage : null,
            'employeeName' => (string) ($employee?->name ?? '—'),
            'employeeNo' => (string) ($employee?->employee_no ?? ''),
            'departmentName' => (string) ($employee?->department?->name ?? '—'),
            'approvalLabel' => $approval->hasApprovalDisplay() ? $approval->approvalLabel : null,
            'approvalNames' => $approval->hasApprovalDisplay() ? $approval->approvalNames : [],
            'approvalHelpText' => $approval->hasApprovalDisplay() ? $approval->approvalHelpText : null,
            'leaveType' => (string) ($leaveRequest->leaveType?->name ?? '—'),
            'leaveTypeColor' => $leaveRequest->leaveType?->color,
            'startDate' => $leaveRequest->start_date?->format('d M Y') ?? '—',
            'endDate' => $leaveRequest->end_date?->format('d M Y') ?? '—',
            'totalDays' => number_format((float) $leaveRequest->total_days, 1, '.', ''),
            'reason' => filled($leaveRequest->reason) ? (string) $leaveRequest->reason : '—',
            'requestUrl' => route('attendance.leave-requests.show', $leaveRequest),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function placeholders(LeaveRequest $leaveRequest): array
    {
        $employee = $leaveRequest->employee;
        $approval = $this->presentApproval->handle($leaveRequest);

        return [
            '{{employee_name}}' => (string) ($employee?->name ?? ''),
            '{{employee_no}}' => (string) ($employee?->employee_no ?? ''),
            '{{department_name}}' => (string) ($employee?->department?->name ?? '—'),
            '{{leave_type}}' => (string) ($leaveRequest->leaveType?->name ?? ''),
            '{{start_date}}' => $leaveRequest->start_date?->format('d M Y') ?? '',
            '{{end_date}}' => $leaveRequest->end_date?->format('d M Y') ?? '',
            '{{total_days}}' => number_format((float) $leaveRequest->total_days, 1, '.', ''),
            '{{reason}}' => filled($leaveRequest->reason) ? (string) $leaveRequest->reason : '—',
            '{{manager_name}}' => $approval->managerName,
            '{{approver_name}}' => $approval->approverName,
            '{{approver_names}}' => $approval->approverNames,
            '{{company_name}}' => (string) ($leaveRequest->company?->name ?? ''),
            '{{request_url}}' => route('attendance.leave-requests.show', $leaveRequest),
        ];
    }

    public function renderTemplate(string $template, LeaveRequest $leaveRequest): string
    {
        return strtr($template, $this->placeholders($leaveRequest));
    }

    /**
     * @param  array{
     *     organizationName: string,
     *     introMessage: string|null,
     *     employeeName: string,
     *     employeeNo: string,
     *     departmentName: string,
     *     approvalLabel: string|null,
     *     approvalNames: list<string>,
     *     approvalHelpText: string|null,
     *     leaveType: string,
     *     leaveTypeColor: string|null,
     *     startDate: string,
     *     endDate: string,
     *     totalDays: string,
     *     reason: string,
     *     requestUrl: string,
     * }  $payload
     */
    public function mailable(string $subjectLine, array $payload, bool $includeCompanyFooter): LeaveRequestSubmittedMail
    {
        return new LeaveRequestSubmittedMail(
            subjectLine: $subjectLine,
            organizationName: $payload['organizationName'],
            introMessage: $payload['introMessage'],
            employeeName: $payload['employeeName'],
            employeeNo: $payload['employeeNo'],
            departmentName: $payload['departmentName'],
            approvalLabel: $payload['approvalLabel'],
            approvalNames: $payload['approvalNames'],
            approvalHelpText: $payload['approvalHelpText'],
            leaveType: $payload['leaveType'],
            leaveTypeColor: $payload['leaveTypeColor'],
            startDate: $payload['startDate'],
            endDate: $payload['endDate'],
            totalDays: $payload['totalDays'],
            reason: $payload['reason'],
            requestUrl: $payload['requestUrl'],
            includeCompanyFooter: $includeCompanyFooter,
        );
    }

    public function approvalContext(LeaveRequest $leaveRequest): LeaveRequestEmailApprovalContext
    {
        return $this->presentApproval->handle($leaveRequest);
    }
}
