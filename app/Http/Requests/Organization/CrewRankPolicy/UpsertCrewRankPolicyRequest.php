<?php

namespace App\Http\Requests\Organization\CrewRankPolicy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertCrewRankPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('crew_operations.rank_policies.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rank_id' => [
                'required',
                'integer',
                Rule::exists('ranks', 'id')->where('is_active', true),
            ],
            'tour_of_duty_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tour_of_duty_days.required' => 'Enter the company Tour of Duty in days.',
            'tour_of_duty_days.min' => 'Tour of Duty must be at least 1 day.',
            'tour_of_duty_days.max' => 'Tour of Duty cannot exceed 365 days.',
        ];
    }
}
