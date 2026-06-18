<?php

namespace App\Http\Requests\Organization\CrewPlanning;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCrewPlanningSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'pool_department_ids' => ['nullable', 'array'],
            'pool_department_ids.*' => [
                'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('status', 'active')),
            ],
        ];
    }
}
