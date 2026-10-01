<?php

namespace App\Http\Requests\Organization\Position;

use App\Rules\CsvImportFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportPositionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('positions.create');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', new CsvImportFile, 'max:512'],
        ];
    }
}
