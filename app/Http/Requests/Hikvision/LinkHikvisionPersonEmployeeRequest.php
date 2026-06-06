<?php

namespace App\Http\Requests\Hikvision;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkHikvisionPersonEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hikvision.persons.link');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'employee_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(
                    fn ($query) => $query->where('company_id', $companyId),
                ),
            ],
        ];
    }
}
