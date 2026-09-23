<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class CheckSimilarRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recruitment.requirements.view')
            || $this->user()?->can('recruitment.requirements.create');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('positions') && ! $this->has('position_ids')) {
            $positions = $this->input('positions');
            if (is_array($positions)) {
                $this->merge([
                    'position_ids' => collect($positions)->pluck('position_id')->filter()->all(),
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'position_ids' => ['required', 'array', 'min:1'],
            'position_ids.*' => ['required', 'integer'],
            'exclude_id' => ['nullable', 'integer'],
        ];
    }
}
