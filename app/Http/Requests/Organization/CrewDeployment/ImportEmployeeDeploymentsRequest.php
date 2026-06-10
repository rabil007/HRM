<?php

namespace App\Http\Requests\Organization\CrewDeployment;

use Illuminate\Foundation\Http\FormRequest;

class ImportEmployeeDeploymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ];
    }
}
