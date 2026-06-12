<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Models\LeaveRequest;
use Illuminate\Validation\Validator;

trait ValidatesOverlappingLeaveRequests
{
    protected function validateOverlappingLeaveRequests(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['employee_id', 'start_date', 'end_date'])) {
            return;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $employeeId = (int) $this->input('employee_id');
        $startDate = $this->input('start_date');
        $endDate = $this->input('end_date');

        $excludeId = null;
        $leaveRequest = $this->route('leave_request');

        if ($leaveRequest instanceof LeaveRequest) {
            $excludeId = $leaveRequest->id;
        }

        $overlapQuery = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate);

        $hasOverlap = $overlapQuery->exists();

        if ($hasOverlap) {
            $validator->errors()->add(
                'start_date',
                'These dates overlap with another pending or approved leave request for this employee.',
            );
        }
    }
}
