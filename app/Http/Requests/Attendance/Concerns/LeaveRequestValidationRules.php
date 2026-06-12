<?php

namespace App\Http\Requests\Attendance\Concerns;

use Illuminate\Validation\Rule;

trait LeaveRequestValidationRules
{
    /**
     * @return array<string, mixed>
     */
    protected function leaveRequestFieldRules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'leave_type_id' => [
                'required',
                'integer',
                Rule::exists('leave_types', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'attachment' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png,doc,docx',
                'mimetypes:application/pdf,image/jpeg,image/png,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'remove_attachment' => ['sometimes', 'boolean'],
        ];
    }
}
