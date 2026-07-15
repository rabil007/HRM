<?php

namespace App\Http\Requests\Organization\SeaServices;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportSeaServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sea_services.import');
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
