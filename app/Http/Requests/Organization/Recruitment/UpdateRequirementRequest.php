<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Enums\Recruitment\RequirementPriority;
use App\Models\RecruitmentRequirement;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Recruitment\RecruiterOptionsQuery;
use App\Support\Recruitment\RequirementAttachmentStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recruitment.requirements.update') ?? false;
    }

    protected function prepareForValidation(): void {}

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $requirement = $this->route('requirement');
        if (! ($requirement instanceof RecruitmentRequirement)) {
            $requirement = RecruitmentRequirement::query()->find($requirement);
        }

        $deadlineRule = $requirement?->required_by_date !== null
            ? 'before_or_equal:'.$requirement->required_by_date->format('Y-m-d')
            : null;

        return [
            'client_id' => ClientAssignmentRules::activeClientIdRules(required: true),
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'client_reference_number' => ['nullable', 'string', 'max:100'],
            'request_received_date' => array_values(array_filter(['required', 'date', $deadlineRule])),
            'location' => ['nullable', 'string', 'max:200'],
            'priority' => ['required', Rule::enum(RequirementPriority::class)],
            'assigned_to' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($companyId): void {
                    if ($value !== null && ! RecruiterOptionsQuery::isValidForCompany((int) $value, $companyId)) {
                        $fail('The selected recruiter is invalid or does not belong to this company.');
                    }
                },
            ],
            'notes' => ['nullable', 'string'],
            'attachment' => [
                'nullable',
                'file',
                'mimes:'.implode(',', RequirementAttachmentStorage::ALLOWED_MIMES),
                'max:'.RequirementAttachmentStorage::MAX_SIZE_KB,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $clientId = $this->input('client_id');
            $projectId = $this->input('project_id');

            ClientAssignmentRules::projectBelongsToClient(
                $validator,
                $clientId !== null && $clientId !== '' ? (int) $clientId : null,
                $projectId !== null && $projectId !== '' ? (int) $projectId : null,
            );
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'request_received_date.before_or_equal' => 'Request received date cannot be after the current required-by date. Use Extend Deadline to adjust the deadline.',
        ];
    }
}
