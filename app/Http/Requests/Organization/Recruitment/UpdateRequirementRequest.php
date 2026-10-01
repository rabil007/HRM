<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Enums\Recruitment\RequirementPriority;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Recruitment\RecruiterOptionsQuery;
use App\Support\Recruitment\RequirementAttachmentStorage;
use App\Support\Recruitment\SyncRequirementNotificationRecipients;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recruitment.requirements.update') ?? false;
    }

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

        $isPending = $requirement?->status === RequirementStatus::PendingApproval;

        if ($isPending) {
            return [
                'assigned_to' => [
                    'required',
                    'integer',
                    function (string $attribute, mixed $value, \Closure $fail) use ($companyId, $requirement): void {
                        if ($value === null || $value === '') {
                            $fail('An assigned recruiter is required while the requirement is pending approval.');

                            return;
                        }

                        if (! RecruiterOptionsQuery::isEligibleApprover((int) $value, $companyId)) {
                            $fail('The selected recruiter must be an active company member with recruitment approval permission.');

                            return;
                        }

                        if ($requirement?->created_by !== null && (int) $requirement->created_by === (int) $value) {
                            $fail('The requester cannot also be the assigned recruiter. Self-approval is not allowed.');
                        }
                    },
                ],
                'notification_recipient_ids' => ['nullable', 'array'],
                'notification_recipient_ids.*' => ['integer'],
            ];
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
            'request_received_date' => array_values(array_filter(['required', 'date', $deadlineRule])),
            'location' => ['nullable', 'string', 'max:200'],
            'priority' => ['required', Rule::enum(RequirementPriority::class)],
            'assigned_to' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($companyId, $requirement): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (! RecruiterOptionsQuery::isEligibleApprover((int) $value, $companyId)) {
                        $fail('The selected recruiter must be an active company member with recruitment approval permission.');

                        return;
                    }

                    if ($requirement?->created_by !== null && (int) $requirement->created_by === (int) $value) {
                        $fail('The requester cannot also be the assigned recruiter. Self-approval is not allowed.');
                    }
                },
            ],
            'notification_recipient_ids' => ['nullable', 'array'],
            'notification_recipient_ids.*' => ['integer'],
            'notes' => ['nullable', 'string'],
            'attachment' => [
                'nullable',
                'file',
                'mimes:'.implode(',', RequirementAttachmentStorage::ALLOWED_MIMES),
                'max:'.RequirementAttachmentStorage::MAX_SIZE_KB,
            ],
        ];
    }

    /**
     * @param  array-key|null  $key
     * @param  mixed  $default
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if (is_array($validated)) {
            unset($validated['client_reference_number']);
        }

        return $validated;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyId = (int) $this->attributes->get('current_company_id');
            $requirement = $this->route('requirement');
            if (! ($requirement instanceof RecruitmentRequirement)) {
                $requirement = RecruitmentRequirement::query()->find($requirement);
            }

            if ($requirement?->status !== RequirementStatus::PendingApproval) {
                $clientId = $this->input('client_id');
                $projectId = $this->input('project_id');

                ClientAssignmentRules::projectBelongsToClient(
                    $validator,
                    $clientId !== null && $clientId !== '' ? (int) $clientId : null,
                    $projectId !== null && $projectId !== '' ? (int) $projectId : null,
                );
            }

            $assignedTo = $this->input('assigned_to', $requirement?->assigned_to);
            $assignedToId = $assignedTo !== null && $assignedTo !== '' ? (int) $assignedTo : null;

            try {
                SyncRequirementNotificationRecipients::normalizeAndValidate(
                    $companyId,
                    is_array($this->input('notification_recipient_ids'))
                        ? $this->input('notification_recipient_ids')
                        : [],
                    $assignedToId,
                );
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
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
