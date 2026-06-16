<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\AttendanceRecordValidationRules;
use App\Models\AttendanceRecord;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttendanceRecordRequest extends FormRequest
{
    use AttendanceRecordValidationRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('attendance.records.create');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->attendanceRecordFieldRules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'source' => AttendanceRecord::SOURCE_MANUAL,
        ]);
    }
}
