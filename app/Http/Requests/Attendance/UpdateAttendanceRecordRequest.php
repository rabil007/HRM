<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\AttendanceRecordValidationRules;
use App\Http\Requests\Attendance\Concerns\ValidatesUniqueAttendanceRecord;
use App\Models\AttendanceRecord;
use App\Support\Attendance\AttendanceRecordVisibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAttendanceRecordRequest extends FormRequest
{
    use AttendanceRecordValidationRules;
    use ValidatesUniqueAttendanceRecord;

    public function authorize(): bool
    {
        if (! $this->user()?->can('attendance.records.update')) {
            return false;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $visibility = app(AttendanceRecordVisibility::class);
        $record = $this->route('attendance_record');

        if ($record instanceof AttendanceRecord) {
            $visibility->assertCanAccess($record, $this->user(), $companyId);
        }

        $visibility->assertCanWriteForEmployee(
            $this->user(),
            $companyId,
            (int) $this->input('employee_id'),
        );

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->attendanceRecordFieldRules(
            $this->route('attendance_record') instanceof AttendanceRecord
                ? $this->route('attendance_record')->id
                : null,
            requireActiveEmployee: false,
        );
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateUniqueAttendanceRecord($validator);
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'source' => AttendanceRecord::SOURCE_MANUAL,
        ]);
    }
}
