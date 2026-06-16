<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Models\AttendanceRecord;
use Illuminate\Validation\Validator;

trait ValidatesUniqueAttendanceRecord
{
    protected function validateUniqueAttendanceRecord(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['employee_id', 'date'])) {
            return;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $employeeId = (int) $this->input('employee_id');
        $date = $this->input('date');

        $excludeId = null;
        $attendanceRecord = $this->route('attendance_record');

        if ($attendanceRecord instanceof AttendanceRecord) {
            $excludeId = $attendanceRecord->id;
        }

        $exists = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->exists();

        if ($exists) {
            $validator->errors()->add(
                'date',
                'An attendance record already exists for this employee on this date. Edit the existing record instead.',
            );
        }
    }
}
