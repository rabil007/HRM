<?php

namespace App\Http\Requests\Settings\MasterData;

use App\Models\Project;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\MasterData\GuardProjectClientChange;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('client_id') && $this->input('client_id') === '') {
            $this->merge(['client_id' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project|null $project */
        $project = $this->route('project');
        $existingClientId = $project?->client_id !== null ? (int) $project->client_id : null;

        return [
            // Uniqueness remains global on title (DB: uq_projects_title) until a
            // soft-delete-safe (client_id, title) unique index can be introduced.
            'title' => [
                'required',
                'string',
                'max:200',
                Rule::unique('projects', 'title')
                    ->ignore($project)
                    ->whereNull('deleted_at'),
            ],
            'client_id' => ClientAssignmentRules::assignableClientIdRules(
                existingClientId: $existingClientId,
                required: false,
            ),
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Project|null $project */
            $project = $this->route('project');

            if (! $project instanceof Project) {
                return;
            }

            $newClientId = $this->input('client_id');
            $resolvedNewClientId = $newClientId !== null && $newClientId !== ''
                ? (int) $newClientId
                : null;

            if (GuardProjectClientChange::wouldBreakEmployeeConsistency($project, $resolvedNewClientId)) {
                $validator->errors()->add(
                    'client_id',
                    'This project cannot be moved to another client because employees are currently assigned to it.',
                );
            }
        });
    }
}
