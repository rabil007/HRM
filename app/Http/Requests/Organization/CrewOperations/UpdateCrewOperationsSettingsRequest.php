<?php

namespace App\Http\Requests\Organization\CrewOperations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCrewOperationsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('sync_sea_service')) {
            $this->merge([
                'sync_sea_service' => filter_var(
                    $this->input('sync_sea_service'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                ) ?? false,
            ]);
        }
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
            'max_home_days' => ['required', 'integer', 'min:0'],
            'sync_sea_service' => ['required', 'boolean'],
        ];
    }
}
