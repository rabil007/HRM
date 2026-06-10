<?php

namespace App\Http\Requests\Organization\Employee;

use App\Http\Requests\Organization\Employee\Concerns\AppliesEmployeeTrainingTemplateRules;
use Illuminate\Foundation\Http\FormRequest;

class BulkStoreEmployeeTrainingRequest extends FormRequest
{
    use AppliesEmployeeTrainingTemplateRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->applyEmployeeTrainingTemplateRules([
            'trainings' => ['required', 'array', 'min:1', 'max:20'],
            'trainings.*.course_id' => $this->requiredCourseIdRules(),
            'trainings.*.issue_date' => ['required', 'date'],
            'trainings.*.expiry_date' => ['nullable', 'date'],
            'trainings.*.institute_center' => ['required', 'string', 'max:255'],
            'trainings.*.country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'trainings.*.certificate' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'mimetypes:application/pdf,image/jpeg,image/png',
                'max:5120',
            ],
        ], wildcard: true);
    }
}
