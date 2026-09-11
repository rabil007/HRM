<?php

namespace App\Http\Requests\Settings\MasterData;

use App\Support\MasterData\ClientAssignmentRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
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
        return [
            // Uniqueness remains global on title (DB: uq_projects_title) until a
            // soft-delete-safe (client_id, title) unique index can be introduced.
            'title' => [
                'required',
                'string',
                'max:200',
                Rule::unique('projects', 'title')->whereNull('deleted_at'),
            ],
            'client_id' => ClientAssignmentRules::activeClientIdRules(required: true),
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
