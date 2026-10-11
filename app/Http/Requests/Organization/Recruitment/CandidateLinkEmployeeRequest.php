<?php

namespace App\Http\Requests\Organization\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class CandidateLinkEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('recruitment.candidates.view')
            && $user->can('recruitment.candidates.convert')
            && $user->can('employees.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],
            'confirmed' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'lock_version' => ['nullable', 'integer'],
        ];
    }
}
