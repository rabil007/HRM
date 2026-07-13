<?php

namespace App\Http\Requests\Organization\Trainings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportTrainingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('training.import');
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
                'mimes:xlsx,xls,csv',
                'max:5120',
            ],
        ];
    }
}
