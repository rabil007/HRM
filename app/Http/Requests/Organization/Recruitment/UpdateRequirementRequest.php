<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Enums\Recruitment\RequirementPriority;
use App\Support\MasterData\ClientAssignmentRules;
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
        $linesKey = $this->has('positions') ? 'positions' : 'lines';

        return [
            'client_id' => ClientAssignmentRules::activeClientIdRules(required: true),
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'client_reference_number' => ['nullable', 'string', 'max:100'],
            'request_received_date' => ['required', 'date'],
            'required_by_date' => ['required', 'date', 'after_or_equal:request_received_date'],
            'location' => ['nullable', 'string', 'max:200'],
            'priority' => ['required', Rule::enum(RequirementPriority::class)],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'notes' => ['nullable', 'string'],
            'attachment' => [
                'nullable',
                'file',
                'mimes:'.implode(',', RequirementAttachmentStorage::ALLOWED_MIMES),
                'max:'.RequirementAttachmentStorage::MAX_SIZE_KB,
            ],
            $linesKey => ['required', 'array', 'min:1'],
            "{$linesKey}.*.id" => ['nullable', 'integer'],
            "{$linesKey}.*.position_id" => [
                'required',
                'integer',
                'distinct',
                Rule::exists('positions', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            "{$linesKey}.*.required_headcount" => ['required', 'integer', 'min:1'],
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
        }

        return $validated;
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
            'lines.required' => 'At least one position line is required.',
            'lines.min' => 'At least one position line is required.',
            'lines.*.position_id.required' => 'Position is required.',
            'lines.*.position_id.distinct' => 'Each position can only be added once per requirement.',
            'lines.*.required_headcount.required' => 'Headcount is required.',
            'lines.*.required_headcount.min' => 'Headcount must be at least 1.',
            'required_by_date.after_or_equal' => 'Required-by date must be on or after request received date.',
        ];
    }
}
