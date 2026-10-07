<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class ExtendDeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $requirement = $this->routeRequirement();

        if ($user === null || $requirement === null) {
            return false;
        }

        abort_unless(
            (int) $requirement->company_id === (int) $this->attributes->get('current_company_id'),
            404,
        );

        return RequirementWorkflowAuthorization::canDirectlyExtendDeadline($user, $requirement)
            || RequirementWorkflowAuthorization::canRequestDeadlineExtension($user, $requirement);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('new_required_by_date') && ! $this->has('new_date')) {
            $this->merge(['new_date' => $this->input('new_required_by_date')]);
        }

        if ($this->has('note') && ! $this->has('reason')) {
            $this->merge(['reason' => $this->input('note')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $requirement = $this->routeRequirement();
        $user = $this->user();
        $minDate = $requirement?->required_by_date?->format('Y-m-d');
        $afterRule = $minDate !== null ? 'after:'.$minDate : 'date';
        $isDirect = $user !== null && $requirement !== null
            && RequirementWorkflowAuthorization::canDirectlyExtendDeadline($user, $requirement);

        return [
            'new_date' => ['required', 'date', $afterRule],
            'reason' => $isDirect
                ? ['nullable', 'string', 'max:1000']
                : ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'new_date.after' => 'The new deadline must be strictly after the current deadline.',
            'reason.required' => 'A reason is required when requesting a deadline extension.',
        ];
    }

    private function routeRequirement(): ?RecruitmentRequirement
    {
        $requirement = $this->route('requirement');
        if ($requirement instanceof RecruitmentRequirement) {
            return $requirement;
        }

        return null;
    }
}
