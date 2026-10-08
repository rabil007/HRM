<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class ChangeHeadcountRequest extends FormRequest
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

        if (RequirementWorkflowAuthorization::canDirectlyReviseHeadcount($user, $requirement)) {
            return true;
        }

        if (
            RequirementWorkflowAuthorization::canProposeHeadcountRevisionAsRequester($user, $requirement)
            || RequirementWorkflowAuthorization::canProposeHeadcountRevisionAsRecruiter($user, $requirement)
        ) {
            return true;
        }

        if (
            RequirementWorkflowAuthorization::isCreator($user, $requirement)
            && $user->can('recruitment.requirements.update')
        ) {
            return true;
        }

        return RequirementWorkflowAuthorization::isAssignedRecruiter($user, $requirement)
            && $user->can('recruitment.requirements.request_headcount_revision');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('requirement_line_id') && $this->has('new_headcount') && ! $this->has('lines')) {
            $this->merge([
                'lines' => [
                    [
                        'id' => (int) $this->input('requirement_line_id'),
                        'required_headcount' => (int) $this->input('new_headcount'),
                    ],
                ],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $requirement = $this->routeRequirement();
        $user = $this->user();
        $requesterProposal = $user !== null
            && $requirement !== null
            && RequirementWorkflowAuthorization::canProposeHeadcountRevisionAsRequester($user, $requirement);

        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.required_headcount' => ['required', 'integer', 'min:1'],
            'reason' => $requesterProposal
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
            'lines.*.required_headcount.min' => 'Headcount must be at least 1.',
            'reason.required' => 'A reason is required when requesting a headcount revision.',
        ];
    }

    private function routeRequirement(): ?RecruitmentRequirement
    {
        $requirement = $this->route('requirement');

        return $requirement instanceof RecruitmentRequirement ? $requirement : null;
    }
}
