<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class ExtendDeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recruitment.requirements.update') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('new_required_by_date') && ! $this->has('new_date')) {
            $this->merge(['new_date' => $this->input('new_required_by_date')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'new_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
