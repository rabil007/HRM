<?php

namespace App\Exports;

use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Reports\Recruitment\RecruitmentReportPresenter;
use App\Support\Reports\Recruitment\RecruitmentSpreadsheetSafeString;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class RecruitmentReportExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<RecruitmentCandidate>  $query
     */
    public function __construct(
        private readonly Builder $query,
        private readonly string $timezone,
        private readonly ?User $user = null,
    ) {}

    /**
     * @param  Builder<RecruitmentCandidate>  $query
     */
    public static function forQuery(Builder $query, string $timezone, ?User $user = null): self
    {
        return new self($query, $timezone, $user);
    }

    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Candidate Name',
            'Candidate Email',
            'Candidate Phone',
            'Nationality',
            'Requirement No',
            'Client',
            'Project',
            'Position',
            'Assigned Recruiter',
            'Stage',
            'Interview Outcome',
            'Expected Joining Date',
            'Actual Joining Date',
            'Joining Readiness',
            'Conversion Status',
            'Employee No',
            'Employee Name',
            'Time to Hire (Days)',
            'Approval to Joining (Days)',
            'Application Date',
        ];
    }

    /**
     * @param  RecruitmentCandidate  $candidate
     * @return list<mixed>
     */
    public function map($candidate): array
    {
        $row = RecruitmentReportPresenter::toArray($candidate, $this->timezone, $this->user);

        $employeeNo = null;
        $employeeName = null;
        if ($row['employee'] !== null) {
            if ($row['employee']['can_view']) {
                $employeeNo = $row['employee']['employee_no'];
                $employeeName = $row['employee']['name'];
            } else {
                $employeeNo = 'Restricted';
                $employeeName = 'Restricted';
            }
        }

        return [
            RecruitmentSpreadsheetSafeString::sanitize($row['name']),
            RecruitmentSpreadsheetSafeString::sanitize($row['email']),
            RecruitmentSpreadsheetSafeString::sanitize($row['phone']),
            RecruitmentSpreadsheetSafeString::sanitize($row['nationality']),
            RecruitmentSpreadsheetSafeString::sanitize($row['requirement']['requirement_number'] ?? ''),
            RecruitmentSpreadsheetSafeString::sanitize($row['client_name']),
            RecruitmentSpreadsheetSafeString::sanitize($row['project_title']),
            RecruitmentSpreadsheetSafeString::sanitize($row['position_title']),
            RecruitmentSpreadsheetSafeString::sanitize($row['recruiter_name']),
            $row['stage_label'],
            $row['interview_outcome_label'],
            $row['expected_joining_date'],
            $row['actual_joining_date'],
            $row['joining_readiness_label'],
            $row['conversion_status_label'],
            RecruitmentSpreadsheetSafeString::sanitize($employeeNo),
            RecruitmentSpreadsheetSafeString::sanitize($employeeName),
            $row['time_to_hire_days'],
            $row['fulfillment_duration_days'],
            $row['created_at'],
        ];
    }
}
