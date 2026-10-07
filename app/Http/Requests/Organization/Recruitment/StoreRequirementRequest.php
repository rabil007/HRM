<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Enums\Recruitment\RequirementPriority;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Recruitment\RecruiterOptionsQuery;
use App\Support\Recruitment\RequirementAttachmentStorage;
use App\Support\Recruitment\SyncRequirementNotificationRecipients;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recruitment.requirements.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('force_create') && ! $this->has('ignore_duplicate_warning')) {
            $this->merge(['ignore_duplicate_warning' => (bool) $this->input('force_create')]);
        }

        if ($this->has('as_open') && ! $this->has('submit_for_approval')) {
            // Legacy clients may still send as_open; map to submit-for-approval.
            $this->merge(['submit_for_approval' => (bool) $this->input('as_open')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $linesKey = $this->has('positions') ? 'positions' : 'lines';
        $isSubmitting = (bool) $this->boolean('submit_for_approval');

        $salaryMinRules = $isSubmitting
            ? ['required', 'numeric', 'min:0', 'decimal:0,2']
            : ['nullable', 'numeric', 'min:0', 'decimal:0,2'];

        $salaryMaxRules = $isSubmitting
            ? ['required', 'numeric', 'min:0', 'decimal:0,2', "gte:{$linesKey}.*.salary_min"]
            : ['nullable', 'numeric', 'min:0', 'decimal:0,2'];

        return [
            'client_id' => ClientAssignmentRules::activeClientIdRules(required: true),
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
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
            'notification_recipient_ids' => ['nullable', 'array'],
            'notification_recipient_ids.*' => ['integer'],
            'notes' => ['nullable', 'string'],
            'submit_for_approval' => ['nullable', 'boolean'],
            'ignore_duplicate_warning' => ['nullable', 'boolean'],
            'attachment' => [
                'nullable',
                'file',
                'mimes:'.implode(',', RequirementAttachmentStorage::ALLOWED_MIMES),
                'max:'.RequirementAttachmentStorage::MAX_SIZE_KB,
            ],
            $linesKey => ['required', 'array', 'min:1'],
            "{$linesKey}.*.position_id" => [
                'required',
                'integer',
                'distinct',
                Rule::exists('positions', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            "{$linesKey}.*.required_headcount" => ['required', 'integer', 'min:1'],
            "{$linesKey}.*.salary_min" => $salaryMinRules,
            "{$linesKey}.*.salary_max" => $salaryMaxRules,
            "{$linesKey}.*.line_notes" => ['nullable', 'string', 'max:1000'],
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
            if (isset($validated['positions']) && ! isset($validated['lines'])) {
                $validated['lines'] = $validated['positions'];
            }

            unset($validated['client_reference_number'], $validated['as_open']);
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
            $clientId = $this->input('client_id');
            $projectId = $this->input('project_id');

            ClientAssignmentRules::projectBelongsToClient(
                $validator,
                $clientId !== null && $clientId !== '' ? (int) $clientId : null,
                $projectId !== null && $projectId !== '' ? (int) $projectId : null,
            );

            $assignedTo = $this->input('assigned_to');
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

            if ((bool) $this->boolean('submit_for_approval')) {
                if ($assignedToId === null) {
                    $validator->errors()->add('assigned_to', 'An assigned recruiter is required before submitting for approval.');
                } elseif (! RecruiterOptionsQuery::isEligibleApprover($assignedToId, $companyId)) {
                    $validator->errors()->add('assigned_to', 'The selected recruiter must be an active company member with recruitment approval permission.');
                }

                if (! ($this->user()?->can('recruitment.requirements.submit') ?? false)) {
                    $validator->errors()->add('submit_for_approval', 'You do not have permission to submit requirements for approval.');
                }

                if ($assignedToId !== null && (int) $this->user()?->id === $assignedToId) {
                    $validator->errors()->add('assigned_to', 'The requester cannot also be the assigned recruiter. Self-approval is not allowed.');
                }
            } else {
                $linesKey = $this->has('positions') ? 'positions' : 'lines';
                $lines = $this->input($linesKey, []);
                if (is_array($lines)) {
                    foreach ($lines as $index => $line) {
                        if (! is_array($line)) {
                            continue;
                        }
                        $min = $line['salary_min'] ?? null;
                        $max = $line['salary_max'] ?? null;

                        if ($min !== null && $min !== '' && $max !== null && $max !== '' && is_numeric($min) && is_numeric($max)) {
                            if ((float) $max < (float) $min) {
                                $validator->errors()->add("{$linesKey}.{$index}.salary_max", 'Maximum salary must be greater than or equal to minimum salary.');
                            }
                        }
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
            'lines.required' => 'At least one position line is required.',
            'lines.min' => 'At least one position line is required.',
            'lines.*.position_id.required' => 'Position is required.',
            'lines.*.position_id.distinct' => 'Each position can only be added once per requirement.',
            'lines.*.required_headcount.required' => 'Headcount is required.',
            'lines.*.required_headcount.min' => 'Headcount must be at least 1.',
            'lines.*.salary_min.required' => 'Minimum salary is required for every position line before submission.',
            'lines.*.salary_min.numeric' => 'Minimum salary must be a valid number.',
            'lines.*.salary_min.min' => 'Minimum salary cannot be negative.',
            'lines.*.salary_min.decimal' => 'Minimum salary may not have more than 2 decimal places.',
            'lines.*.salary_max.required' => 'Maximum salary is required for every position line before submission.',
            'lines.*.salary_max.numeric' => 'Maximum salary must be a valid number.',
            'lines.*.salary_max.min' => 'Maximum salary cannot be negative.',
            'lines.*.salary_max.decimal' => 'Maximum salary may not have more than 2 decimal places.',
            'lines.*.salary_max.gte' => 'Maximum salary must be greater than or equal to minimum salary.',
            'positions.required' => 'At least one position line is required.',
            'positions.min' => 'At least one position line is required.',
            'positions.*.position_id.required' => 'Position is required.',
            'positions.*.position_id.distinct' => 'Each position can only be added once per requirement.',
            'positions.*.required_headcount.required' => 'Headcount is required.',
            'positions.*.required_headcount.min' => 'Headcount must be at least 1.',
            'positions.*.salary_min.required' => 'Minimum salary is required for every position line before submission.',
            'positions.*.salary_min.numeric' => 'Minimum salary must be a valid number.',
            'positions.*.salary_min.min' => 'Minimum salary cannot be negative.',
            'positions.*.salary_min.decimal' => 'Minimum salary may not have more than 2 decimal places.',
            'positions.*.salary_max.required' => 'Maximum salary is required for every position line before submission.',
            'positions.*.salary_max.numeric' => 'Maximum salary must be a valid number.',
            'positions.*.salary_max.min' => 'Maximum salary cannot be negative.',
            'positions.*.salary_max.decimal' => 'Maximum salary may not have more than 2 decimal places.',
            'positions.*.salary_max.gte' => 'Maximum salary must be greater than or equal to minimum salary.',
            'required_by_date.after_or_equal' => 'Required-by date must be on or after the Request Received from Client date.',
            'request_received_date.required' => 'Request Received from Client is required.',
            'request_received_date.date' => 'Request Received from Client must be a valid date.',
        ];
    }
}
