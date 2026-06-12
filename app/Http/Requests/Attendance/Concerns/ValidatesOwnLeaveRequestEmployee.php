<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Support\Attendance\LeaveRequestVisibility;
use Illuminate\Validation\Validator;

trait ValidatesOwnLeaveRequestEmployee
{
    protected function validateOwnLeaveRequestEmployee(Validator $validator): void
    {
        $visibility = app(LeaveRequestVisibility::class);
        $user = $this->user();
        $companyId = (int) $this->attributes->get('current_company_id');

        if ($visibility->canViewAll($user)) {
            return;
        }

        $linkedEmployeeId = $visibility->linkedEmployeeId($user, $companyId);

        if ($linkedEmployeeId === null) {
            if ($this->isMethod('POST')) {
                $validator->errors()->add(
                    'employee_id',
                    'You must be linked to an employee record to manage leave requests.',
                );
            }

            return;
        }

        if ((int) $this->input('employee_id') !== $linkedEmployeeId) {
            $validator->errors()->add(
                'employee_id',
                'You can only manage leave requests for your own employee record.',
            );
        }
    }
}
