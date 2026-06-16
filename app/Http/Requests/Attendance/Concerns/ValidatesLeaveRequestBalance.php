<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Models\LeaveRequest;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Validation\Validator;
use RuntimeException;

trait ValidatesLeaveRequestBalance
{
    protected function validateLeaveRequestBalance(Validator $validator, ?int $ignoreLeaveRequestId = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $employeeId = (int) $this->input('employee_id');
        $leaveTypeId = (int) $this->input('leave_type_id');
        $startDate = (string) $this->input('start_date');
        $endDate = (string) $this->input('end_date');

        if ($employeeId <= 0 || $leaveTypeId <= 0 || $startDate === '' || $endDate === '') {
            return;
        }

        $ignore = null;

        if ($ignoreLeaveRequestId !== null) {
            $ignore = LeaveRequest::query()
                ->where('company_id', $companyId)
                ->find($ignoreLeaveRequestId);
        }

        try {
            app(LeaveBalanceManager::class)->assertCanReserve(
                $companyId,
                $employeeId,
                $leaveTypeId,
                $startDate,
                $endDate,
                $ignore,
            );
        } catch (RuntimeException $exception) {
            $validator->errors()->add('leave_type_id', $exception->getMessage());
        }
    }
}
