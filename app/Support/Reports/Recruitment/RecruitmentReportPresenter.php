<?php

namespace App\Support\Reports\Recruitment;

use App\Enums\Recruitment\CandidateStage;
use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Carbon\Carbon;

final class RecruitmentReportPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(RecruitmentCandidate $candidate, string $timezone, ?User $user = null): array
    {
        $requirement = $candidate->requirement;
        $line = $candidate->line;
        $employee = $candidate->employee;
        $companyId = (int) $candidate->company_id;

        $canViewEmployee = false;
        if ($employee !== null && $user !== null) {
            $canViewEmployee = $user->can('employees.view')
                && EmployeeVisibilityScope::canAccess($user, $employee, $companyId);
        }

        $employeePayload = null;
        if ($candidate->employee_id !== null) {
            $employeePayload = [
                'id' => $canViewEmployee ? (int) $employee?->id : null,
                'name' => $canViewEmployee ? (string) $employee?->name : null,
                'employee_no' => $canViewEmployee ? (string) $employee?->employee_no : null,
                'can_view' => $canViewEmployee,
            ];
        }

        $conversionStatus = $candidate->employee_id !== null
            ? 'converted'
            : ($candidate->stage === CandidateStage::Joined ? 'pending' : 'not_applicable');

        $conversionStatusLabel = match ($conversionStatus) {
            'converted' => 'Converted',
            'pending' => 'Pending Conversion',
            default => 'Not Applicable',
        };

        $timeToHireDays = RecruitmentReportQuery::calculateCandidateToJoinedDays($candidate, $timezone);
        $fulfillmentDurationDays = RecruitmentReportQuery::calculateRequirementApprovalToJoinedDays($candidate, $timezone);

        return [
            'id' => (int) $candidate->id,
            'name' => (string) $candidate->name,
            'email' => $candidate->email,
            'phone' => $candidate->phone,
            'nationality' => $candidate->nationality?->name,
            'requirement' => [
                'id' => $candidate->recruitment_requirement_id,
                'requirement_number' => (string) ($requirement?->requirement_number ?? $candidate->requirement_number_snapshot),
                'status' => $requirement?->status?->value,
                'status_label' => $requirement?->status?->label(),
            ],
            'client_name' => $requirement?->client?->name,
            'project_title' => $requirement?->project?->title,
            'position_title' => (string) ($line?->position?->title ?? $candidate->position_title_snapshot),
            'recruiter_name' => $requirement?->assignedRecruiter?->name,
            'stage' => $candidate->stage->value,
            'stage_label' => $candidate->stage->label(),
            'stage_badge' => $candidate->stage->badgeVariant(),
            'interview_outcome' => $candidate->interview_outcome?->value,
            'interview_outcome_label' => $candidate->interview_outcome?->label(),
            'expected_joining_date' => $candidate->expected_joining_date?->toDateString(),
            'actual_joining_date' => $candidate->actual_joining_date?->toDateString(),
            'joining_readiness_status' => $candidate->joining_readiness_status?->value,
            'joining_readiness_label' => $candidate->joining_readiness_status?->label(),
            'joining_readiness_badge' => $candidate->joining_readiness_status?->badgeVariant(),
            'conversion_status' => $conversionStatus,
            'conversion_status_label' => $conversionStatusLabel,
            'employee' => $employeePayload,
            'time_to_hire_days' => $timeToHireDays,
            'fulfillment_duration_days' => $fulfillmentDurationDays,
            'created_at' => self::formatDateTime($candidate->created_at, $timezone),
        ];
    }

    private static function formatDateTime(mixed $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Carbon) {
            try {
                $value = Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return $value->copy()->timezone($timezone)->format('d M Y H:i');
    }
}
