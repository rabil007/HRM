<?php

namespace App\Http\Requests\Organization;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCrewAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')->where('is_active', true)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'vessel_id' => ['nullable', 'integer', Rule::exists('vessels', 'id')->where('is_active', true)],
            'company_visa_type_id' => ['nullable', 'integer', Rule::exists('company_visa_types', 'id')->where('is_active', true)],
            'planned_join_at' => ['nullable', 'date'],
            'planned_signoff_at' => ['nullable', 'date', 'after_or_equal:planned_join_at'],
            'planned_travel_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
