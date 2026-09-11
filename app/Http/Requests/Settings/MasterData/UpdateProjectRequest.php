<?php

namespace App\Http\Requests\Settings\MasterData;

use App\Models\Project;
use App\Support\MasterData\ClientAssignmentRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
}
