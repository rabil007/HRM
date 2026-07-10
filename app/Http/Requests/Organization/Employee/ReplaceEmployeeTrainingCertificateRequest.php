<?php

namespace App\Http\Requests\Organization\Employee;

use App\Http\Requests\Organization\Employee\Concerns\AppliesEmployeeTrainingTemplateRules;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceEmployeeTrainingCertificateRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
        ]);
    }
}
