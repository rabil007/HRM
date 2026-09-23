<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Models\RecruitmentRequirement;
use Illuminate\Foundation\Http\FormRequest;

class ReopenRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recruitment.requirements.reopen') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $requirement = $this->route('requirement');
        if (! ($requirement instanceof RecruitmentRequirement)) {
            $requirement = RecruitmentRequirement::query()->find($requirement);
        }

        $minDate = $requirement?->request_received_date?->format('Y-m-d');
        $afterOrEqualRule = $minDate !== null ? 'after_or_equal:'.$minDate : 'date';

        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'new_required_by_date' => ['nullable', 'date', $afterOrEqualRule],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'new_required_by_date.after_or_equal' => 'The new deadline must be on or after the original request received date.',
        ];
    }
}
