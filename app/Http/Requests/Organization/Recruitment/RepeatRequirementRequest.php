<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Enums\Recruitment\RequirementPriority;
use App\Models\RecruitmentRequirement;
use App\Support\Recruitment\RecruiterOptionsQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepeatRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user?->can('recruitment.requirements.view') && $user?->can('recruitment.requirements.create'));
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('lines') || empty($this->input('lines'))) {
            $requirement = $this->route('requirement');
            if ($requirement instanceof RecruitmentRequirement) {
                $lines = $requirement->lines->map(fn ($line) => [
                    'position_id' => $line->position_id,
                    'required_headcount' => $line->required_headcount,
                    'salary_min' => $line->salary_min,
                    'salary_max' => $line->salary_max,
                    'salary_currency_code' => $line->salary_currency_code,
                    'line_notes' => $line->line_notes,
                ])->all();
                $this->merge(['lines' => $lines]);
            }
        }

        if ($this->has('reason') && ! $this->has('notes')) {
            $this->merge(['notes' => $this->input('reason')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'request_received_date' => ['required', 'date'],
            'required_by_date' => ['required', 'date', 'after_or_equal:request_received_date'],
            'location' => ['nullable', 'string', 'max:200'],
            'priority' => ['required', Rule::enum(RequirementPriority::class)],
            'assigned_to' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($companyId): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (! RecruiterOptionsQuery::isEligibleApprover((int) $value, $companyId)) {
                        $fail('The selected recruiter must be an active company member with recruitment approval permission.');
                    }
                },
            ],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.position_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('positions', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'lines.*.required_headcount' => ['required', 'integer', 'min:1'],
            'lines.*.salary_min' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.salary_max' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.salary_currency_code' => ['nullable', 'string', 'size:3'],
            'lines.*.line_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
