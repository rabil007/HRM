<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Models\RecruitmentRequirement;
use Illuminate\Foundation\Http\FormRequest;

class ExtendDeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recruitment.requirements.update') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('new_required_by_date') && ! $this->has('new_date')) {
            $this->merge(['new_date' => $this->input('new_required_by_date')]);
        }
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

        $minDate = $requirement?->required_by_date?->format('Y-m-d');
        $afterRule = $minDate !== null ? 'after:'.$minDate : 'date';

        return [
            'new_date' => ['required', 'date', $afterRule],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'new_date.after' => 'The new deadline must be strictly after the current deadline.',
        ];
    }
}
