<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class ReturnRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recruitment.requirements.approve') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'return_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'return_reason.required' => 'A return reason is required.',
            'return_reason.min' => 'Please provide a more detailed return reason.',
        ];
    }
}
