<?php

namespace App\Http\Requests\Organization;

use App\Models\CrewAssignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ValidateHistoricalCrewImportRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please choose an Excel file to validate.',
            'file.mimes' => 'The file must be an Excel workbook (.xlsx or .xls).',
            'file.max' => 'The Excel file may not be larger than 10 MB.',
        ];
    }
}
