<?php

namespace App\Http\Requests\Organization;

use App\Models\CrewAssignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExecuteHistoricalCrewImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can('createHistorical', CrewAssignment::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'max:10240',
            ],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
            'confirmed' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please choose an Excel file to import.',
            'confirmed.accepted' => 'Please confirm you reviewed the validation results before importing.',
            'idempotency_key.required' => 'A valid import confirmation key is required.',
        ];
    }
}
