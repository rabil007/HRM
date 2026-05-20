<?php

namespace App\Http\Requests\Settings\MasterData;

use App\Rules\CsvImportFile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportRanksRequest extends FormRequest
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
            'file' => ['required', 'file', new CsvImportFile, 'max:512'],
        ];
    }
}
